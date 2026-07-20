<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;
use Tesseract\NativeCollector\NativeAgent;
use Tesseract\NativeCollector\Pairing;

#[Name('tesseract-native-agent')]
#[Title('Tesseract Native Agent')]
#[Description('Reports whether the on-device native agent is reachable, the paired project, and the capabilities advertised to the desktop. Useful for confirming the device is connected before issuing native history queries.')]
#[Uri('tesseract-native://agent')]
#[MimeType('application/json')]
class AgentStatusResource extends Resource
{
    public function __construct(
        protected NativeAgent $agent,
        protected Pairing $pairing,
    ) {}

    public function handle(): Response
    {
        $pairing = $this->pairing->read() ?? [];

        $payload = [
            'agentAvailable' => $this->agent->isAvailable(),
            'project' => [
                'key' => is_string($pairing['project_id'] ?? null) ? $pairing['project_id'] : sha1((string) base_path()),
                'path' => is_string($pairing['project_path'] ?? null) ? $pairing['project_path'] : (string) base_path(),
                'paired' => $pairing !== [],
            ],
            'capabilities' => (array) config('tesseract-native.capabilities', []),
            'hint' => $this->agent->isAvailable()
                ? 'The agent bridge is present. Launch the app on the device with Tesseract Desktop running to capture a session.'
                : 'Running off-device (no native bridge). History still reads back from the desktop loopback for sessions captured on a connected device.',
        ];

        return Response::text(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
