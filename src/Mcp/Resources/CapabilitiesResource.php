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

#[Name('tesseract-native-capabilities')]
#[Title('Tesseract Native capabilities')]
#[Description('Lists the native history operations this plugin exposes and how native concepts map onto the shared history streams.')]
#[Uri('tesseract-native://capabilities')]
#[MimeType('application/json')]
class CapabilitiesResource extends Resource
{
    public function handle(): Response
    {
        $payload = [
            'pluginVersion' => '1.0.0',
            'runtime' => 'native',
            'tools' => [
                ['name' => 'tesseract-debug', 'operation' => 'debug', 'role' => 'first call for any "the app broke" prompt on the mobile app'],
                ['name' => 'tesseract-search', 'operation' => 'search', 'role' => 'cursor-paginated list query (errors, logs, requests, queries, activity, sessions)'],
                ['name' => 'tesseract-detail', 'operation' => 'detail', 'role' => 'full record for a single error/request id with surrounding context'],
                ['name' => 'tesseract-screens', 'operation' => 'local:native-router', 'role' => 'list registered screens/jump targets - read in-process from the shell router, no device or desktop needed'],
                ['name' => 'tesseract-view-tree', 'operation' => 'search:requests', 'role' => 'current screen\'s forwarded component tree from desktop history'],
                ['name' => 'tesseract-native-action', 'operation' => 'desktop-agent:action', 'role' => 'scoped write/control adapter for native, mirror, and live command workflows; may invoke destructive actions when explicitly requested'],
                ['name' => 'tesseract-screenshot', 'operation' => 'desktop-agent:mirror.screenshot', 'role' => 'capture a PNG screenshot from an Android device/emulator or iOS simulator'],
                ['name' => 'tesseract-screen-find', 'operation' => 'desktop-agent:screen.items.find', 'role' => 'find native or web live-capture screen items and return target descriptors'],
                ['name' => 'tesseract-screen-instrument', 'operation' => 'desktop-agent:screen.item.instrument', 'role' => 'highlight, scroll, style, patch, or dispatch against a found screen target'],
            ],
            'desktopControl' => [
                'resource' => 'tesseract-native://desktop-status',
                'tool' => 'tesseract-native-action',
                'pairing' => 'Use `tesseractctl pair`; this server resolves TESSERACT_AGENT_TOKEN/TESSERACT_AGENT_BASE_URL first, then the shared tesseractctl config, then the desktop descriptor.',
                'nativeActions' => ['native.navigate', 'native.dispatch-event', 'native.set-scope', 'native.call', 'native.set-style', 'mirror.input', 'mirror.probe', 'mirror.screenshot', 'screen.items.find', 'screen.item.instrument'],
                'screenTools' => ['tesseract-screenshot', 'tesseract-screen-find', 'tesseract-screen-instrument'],
                'unavailableResponse' => '{ available: false, reason, hint }',
            ],
            'nativeMapping' => [
                'requests' => 'Native has no HTTP cycle. Screen navigations, dispatched UI interactions, and component lifecycle are captured as stand-in requests.',
                'errors' => 'Shell render/dispatch failures are captured as native-runtime exceptions; in-process Laravel exceptions are php-runtime.',
                'interactions' => 'The web DOM-interaction stream does not apply on native; inspect interactions through `kind: "requests"` instead.',
            ],
            'sessionResolution' => [
                'order' => ['literal sessionId', 'latest for project (key or path)', 'latest globally (with meta.warning: no-project-context)'],
                'autoProjectIdentity' => 'Tools auto-attach project.{key,path} from the pairing file so the desktop resolves the latest native session for this project.',
            ],
            'sharedArguments' => [
                'view' => "'summary' (default) | 'detail' - detail includes frames and full payloads.",
                'maxBytes' => 'Hard ceiling per response (1024-65536, default 16384). Heavy fields are dropped first when over budget.',
            ],
            'responseMeta' => [
                'meta.truncated[]' => 'Each entry: { path, totalAvailable, returned, next: { tool, args } }. The next call recovers the dropped data.',
                'meta.suggestedSessionId' => 'Set when the resolver walked back to a project-previous session. Carry this id into follow-up calls.',
                'meta.recentSessions' => 'Debug-only. Top sibling native sessions for the project with errorCount/requestCount/logCount.',
                'meta.warning' => 'Set to "no-project-context" when neither sessionId nor project identity was provided and a global fallback session was used.',
            ],
        ];

        return Response::text(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
