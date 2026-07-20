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

#[Name('tesseract-screenshot')]
#[Title('Tesseract Native Screenshot')]
#[Description('Captures a PNG screenshot from the selected Android device/emulator or iOS simulator through the desktop Agent API. Returns the Agent API envelope with data.pngBase64 on success.')]
#[IsReadOnly]
#[IsOpenWorld]
class TesseractScreenshotTool extends Tool
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
            'deviceId' => $schema->string()->required()->description('Android device id/AVD name or iOS simulator UDID.'),
            'platform' => $schema->string()->enum(['android', 'ios'])->required(),
            'adbPath' => $schema->string()->description('Required for Android screenshots. Path to adb resolved by the desktop/device tooling.'),
            'sessionId' => $schema->string()->description('Optional mirror session id for audit correlation.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'deviceId' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:android,ios'],
            'adbPath' => ['nullable', 'string', 'max:2000'],
            'sessionId' => ['nullable', 'string', 'max:255'],
        ]);

        return Response::text(json_encode(
            $this->desktop->action('mirror.screenshot', $validated),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
