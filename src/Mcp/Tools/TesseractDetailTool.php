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

#[Name('tesseract-detail')]
#[Title('Tesseract Native Detail')]
#[Description('Use this after `tesseract-debug` or `tesseract-search` once you have a specific id. Pass `kind: "error"` or `kind: "request"` and the `id` (both required) to get the full record — frames, source snippets, full payload — plus a surrounding context window. On native, `kind: "request"` resolves a navigation, an interaction (with its handler and scope delta), or a component lifecycle record. Set `includeContext: false` for tight loops where the trace alone is enough.')]
#[IsReadOnly]
class TesseractDetailTool extends AbstractNativeTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(['error', 'request'])->required()->description('Detail record type. `request` covers native navigations, interactions, and lifecycle records.'),
            'id' => $schema->string()->required()->description('The error entry id or request id to fetch.'),
            'sessionId' => $schema->string()->description('Optional Tesseract session id. Defaults to the most recent native session for the current project.'),
            'lookbackMs' => $schema->number()->min(0)->description('Optional context-window lookback in milliseconds.'),
            'includeContext' => $schema->boolean()->default(true)->description('When false, skips the surrounding context bundle. Use false for tight loops where you only need the trace.'),
            'view' => $schema->string()->enum(['summary', 'detail'])->default('detail')->description('Default detail; use summary to drop heavy fields like frames and source snippets.'),
            'maxBytes' => $schema->integer()->min(1024)->max(65536)->default(16384),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:error,request'],
            'id' => ['required', 'string', 'max:191'],
            'sessionId' => ['nullable', 'string', 'max:64'],
            'lookbackMs' => ['nullable', 'numeric', 'min:0'],
            'includeContext' => ['nullable', 'boolean'],
            'view' => ['nullable', 'in:summary,detail'],
            'maxBytes' => ['nullable', 'integer', 'min:1024', 'max:65536'],
        ]);

        return $this->call('detail', $validated);
    }
}
