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
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Tesseract\NativeCollector\Mcp\DesktopControlClient;

#[Name('tesseract-native-action')]
#[Title('Tesseract Native Desktop Action')]
#[Description('Runs a scoped Tesseract Desktop Agent API action for native/mirror workflows. Returns {available:false, reason, hint} instead of failing when the desktop is unavailable, unpaired, or unauthorized.')]
#[IsReadOnly(false)]
#[IsOpenWorld]
#[IsDestructive]
#[IsIdempotent(false)]
class TesseractNativeActionTool extends Tool
{
    public function __construct(
        protected DesktopControlClient $desktop,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'actionId' => $schema->string()->required()->description('Canonical desktop action id, for example native.navigate, native.set-scope, or mirror.input.'),
            'arguments' => $schema->object()->description('Action arguments passed through to the desktop Agent API.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'actionId' => ['required', 'string', 'max:191'],
            'arguments' => ['nullable', 'array'],
        ]);

        $payload = $this->desktop->action(
            $validated['actionId'],
            is_array($validated['arguments'] ?? null) ? $validated['arguments'] : [],
        );

        return Response::text(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
