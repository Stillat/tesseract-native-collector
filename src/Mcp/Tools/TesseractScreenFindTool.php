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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Tesseract\NativeCollector\Mcp\DesktopControlClient;

#[Name('tesseract-screen-find')]
#[Title('Tesseract Screen Find')]
#[Description('Finds items in the active live screen tree through the desktop Agent API. Works for native mobile captures and web DOM captures; returns target descriptors suitable for tesseract-screen-instrument.')]
#[IsReadOnly]
#[IsOpenWorld]
class TesseractScreenFindTool extends Tool
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
            'query' => $schema->string()->description('Text, native ref, source path, tag/type, instrumentation key, path, or node id to match. Omit to return the first captured items.'),
            'surface' => $schema->string()->enum(['auto', 'web', 'native'])->default('auto'),
            'captureId' => $schema->string()->description('Optional live capture id. Defaults to the desktop active capture.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:500'],
            'surface' => ['nullable', 'in:auto,web,native'],
            'captureId' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return Response::text(json_encode(
            $this->desktop->action('screen.items.find', $validated),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
