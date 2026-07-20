<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('tesseract-debug')]
#[Title('Tesseract Native Debug')]
#[Description('Call this first whenever a user reports anything broken in the NativePHP mobile app: "crashed", "red screen", "frozen screen", "the counter stopped", "the app closed". Returns the latest crash candidate (a native render error or PHP exception, or null), plus a `diagnostics.patterns[]` list that separates a real crash from a non-crash pattern (interaction flood on a control, navigation loop, log burst). If `crash` is null, the symptom is not a crash; read each `pattern.verification.status` before forming a hypothesis. Empty session returns a useful empty envelope, never an error. Always safe to call with no arguments.')]
#[IsReadOnly]
class TesseractDebugTool extends AbstractNativeTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sessionId' => $schema->string()->description('Optional Tesseract session id. When omitted the desktop returns the most recent native session for the current project.'),
            'runtime' => $schema->string()->enum(['all', 'native', 'php'])->default('all')->description("Runtime filter. 'native' is the shell render/dispatch layer; 'php' is the in-process Laravel runtime. Default 'all'."),
            'lookbackMs' => $schema->number()->min(0)->description('Optional context-window lookback in milliseconds.'),
            'view' => $schema->string()->enum(['summary', 'detail'])->default('summary')->description('Response shape. Default summary; detail for full traces and frames.'),
            'maxBytes' => $schema->integer()->min(1024)->max(65536)->default(16384)->description('Soft byte ceiling for the response.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'sessionId' => ['nullable', 'string', 'max:64'],
            'runtime' => ['nullable', 'in:all,native,php'],
            'lookbackMs' => ['nullable', 'numeric', 'min:0'],
            'view' => ['nullable', 'in:summary,detail'],
            'maxBytes' => ['nullable', 'integer', 'min:1024', 'max:65536'],
        ]);

        return $this->call('debug', $validated);
    }
}
