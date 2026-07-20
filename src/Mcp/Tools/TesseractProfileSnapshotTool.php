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

#[Name('tesseract-profile-snapshot')]
#[Title('Tesseract Native Profile Snapshot')]
#[Description('Collects a structured desktop process and memory snapshot through the paired Tesseract Desktop Agent API.')]
#[IsReadOnly]
#[IsOpenWorld]
class TesseractProfileSnapshotTool extends Tool
{
    public function __construct(
        protected DesktopControlClient $desktop,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return Response::text(json_encode(
            $this->desktop->action('profiling.snapshot', []),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
