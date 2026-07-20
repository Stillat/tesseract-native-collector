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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Tesseract\NativeCollector\Mcp\Support\ProjectIdentity;
use Tesseract\NativeCollector\Support\NativeRouteCollector;

/**
 * Lists the app's registered native screens (routes / jump targets). Read from
 * the shell router in-process, so it needs no live device or desktop — it
 * answers "what screens exist and which components back them?" for planning a
 * navigation or mapping a route to its NativeComponent.
 */
#[Name('tesseract-screens')]
#[Title('Tesseract Native Screens')]
#[Description('Lists the app\'s registered native screens (routes and their backing NativeComponent classes) — the jump targets the desktop can navigate to. Read directly from the shell router in this process, so it works without a live device or the desktop loopback (returns an empty list on a non-native runtime with a `meta.available: false` hint). Filter with `query`; use it to map a route to its component before reading history for that screen.')]
#[IsReadOnly]
class TesseractScreensTool extends Tool
{
    public function __construct(
        protected NativeRouteCollector $routes,
        protected ProjectIdentity $projectIdentity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Optional case-insensitive filter matched against a screen\'s path, name, or component class.'),
            'maxBytes' => $schema->integer()->min(1024)->max(65536)->default(16384)->description('Soft byte ceiling for the response. Screens past the budget are dropped and noted in meta.truncated.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:200'],
            'maxBytes' => ['nullable', 'integer', 'min:1024', 'max:65536'],
        ]);

        $query = isset($validated['query']) ? mb_strtolower(trim((string) $validated['query'])) : '';
        $maxBytes = (int) ($validated['maxBytes'] ?? 16384);

        $screens = $this->routes->routes();

        if ($query !== '') {
            $screens = array_values(array_filter($screens, static function (array $screen) use ($query): bool {
                return str_contains(mb_strtolower($screen['path']), $query)
                    || str_contains(mb_strtolower($screen['name']), $query)
                    || str_contains(mb_strtolower($screen['component']), $query);
            }));
        }

        $totalAvailable = count($screens);
        $returned = $screens;
        $truncated = [];

        while ($returned !== [] && $this->byteLength($this->payload($returned, $totalAvailable, [])) > $maxBytes) {
            array_pop($returned);
        }

        if (count($returned) < $totalAvailable) {
            $truncated[] = [
                'path' => 'screens',
                'totalAvailable' => $totalAvailable,
                'returned' => count($returned),
                'next' => ['tool' => 'tesseract-screens', 'args' => ['query' => $validated['query'] ?? '', 'maxBytes' => min($maxBytes * 2, 65536)]],
            ];
        }

        return Response::text($this->payload($returned, $totalAvailable, $truncated));
    }

    /**
     * @param  array<int, array{path: string, name: string, component: string}>  $screens
     * @param  array<int, array<string, mixed>>  $truncated
     */
    protected function payload(array $screens, int $totalAvailable, array $truncated): string
    {
        return json_encode([
            'screens' => $screens,
            'counts' => [
                'returned' => count($screens),
                'totalAvailable' => $totalAvailable,
            ],
            'meta' => [
                'project' => $this->projectIdentity->projectPayload(),
                'source' => 'native-router-reflection',
                'available' => $this->routes->available(),
                'hint' => $this->routes->available()
                    ? 'Screens read from the live shell router in-process.'
                    : 'No native shell on this runtime; screens are only enumerable when the app\'s NativePHP router is present.',
                'truncated' => $truncated,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function byteLength(string $json): int
    {
        return strlen($json);
    }
}
