<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Client;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Request;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeEventHandlers;
use Tesseract\NativeCollector\Commands\ReservedNativeEventRegistrar;
use Tesseract\NativeCollector\Mcp\TesseractNativeServer;
use Tesseract\NativeCollector\Mcp\Tools\TesseractDebugTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractDetailTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractNativeActionTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractProfileSnapshotTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractScreenFindTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractScreenInstrumentTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractScreenshotTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractScreensTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractSearchTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractViewTreeTool;
use Tesseract\NativeCollector\TesseractNativeCollectorServiceProvider;

function bootNativeProvider(): TesseractNativeCollectorServiceProvider
{
    $provider = new TesseractNativeCollectorServiceProvider(app());
    $provider->register();

    return $provider;
}

function nativeMcpProcessCode(): string
{
    $packageRoot = var_export(dirname(__DIR__, 2), true);
    $laravelRoot = var_export(base_path(), true);

    return <<<PHP
chdir({$laravelRoot});
putenv('TESSERACT_AGENT_BASE_URL=http://127.0.0.1:59998');
putenv('TESSERACT_AGENT_TOKEN');
require {$packageRoot}.'/vendor/autoload.php';
\$app = require {$laravelRoot}.'/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set('tesseract-native.enabled', false);
config()->set('tesseract-native.boost.enabled', false);
config()->set('tesseract-native.mcp.enabled', true);
config()->set('tesseract-native.agent_control.base_url', 'http://127.0.0.1:59998');
config()->set('tesseract-native.agent_control.token', null);
\$provider = new Tesseract\NativeCollector\TesseractNativeCollectorServiceProvider(\$app);
\$provider->register();
config()->set('tesseract-native.enabled', false);
config()->set('tesseract-native.boost.enabled', false);
config()->set('tesseract-native.mcp.enabled', true);
config()->set('tesseract-native.agent_control.base_url', 'http://127.0.0.1:59998');
config()->set('tesseract-native.agent_control.token', null);
\$provider->boot();
\$server = Laravel\Mcp\Facades\Mcp::getLocalServer('tesseract-native');
if (! \$server) {
    fwrite(STDERR, 'tesseract-native MCP server was not registered.');
    exit(1);
}
\$server();
PHP;
}

it('registers the native MCP server on the local handle when Boost is not the host', function (): void {
    $provider = bootNativeProvider();

    config()->set('tesseract-native.enabled', false);
    config()->set('tesseract-native.mcp.enabled', true);
    config()->set('tesseract-native.boost.enabled', false);

    $provider->boot();

    expect(Mcp::getLocalServer('tesseract-native'))->not->toBeNull();
});

it('does not register the local MCP server when disabled', function (): void {
    $provider = bootNativeProvider();

    config()->set('tesseract-native.enabled', false);
    config()->set('tesseract-native.mcp.enabled', false);
    config()->set('tesseract-native.boost.enabled', false);

    $provider->boot();

    expect(Mcp::getLocalServer('tesseract-native'))->toBeNull();
});

it('pushes the native tools into Boost instead of a second MCP server when Boost is present', function (): void {
    config()->set('boost.mcp.tools.include', []);
    config()->set('tesseract-native.boost.enabled', true);

    $provider = new class(app()) extends TesseractNativeCollectorServiceProvider
    {
        protected function boostMcpCommandAvailable(): bool
        {
            return true;
        }
    };
    $provider->register();

    config()->set('tesseract-native.enabled', false);
    config()->set('tesseract-native.mcp.enabled', true);
    $provider->boot();

    expect(config('boost.mcp.tools.include'))->toContain(
        TesseractDebugTool::class,
        TesseractSearchTool::class,
        TesseractDetailTool::class,
        TesseractScreensTool::class,
        TesseractViewTreeTool::class,
        TesseractNativeActionTool::class,
        TesseractProfileSnapshotTool::class,
        TesseractScreenshotTool::class,
        TesseractScreenFindTool::class,
        TesseractScreenInstrumentTool::class,
    )->and(Mcp::getLocalServer('tesseract-native'))->toBeNull();
});

it('serves native MCP tools over a stdio subprocess', function (): void {
    $client = Client::local(PHP_BINARY, ['-r', nativeMcpProcessCode()])
        ->withTimeout(10);

    try {
        $tools = $client->tools();

        expect($tools->keys()->all())->toContain(
            'tesseract-debug',
            'tesseract-native-action',
            'tesseract-profile-snapshot',
            'tesseract-screenshot',
            'tesseract-screen-find',
            'tesseract-screen-instrument',
        );

        $resources = $client->resources();

        expect($resources->keys()->all())->toContain(
            'tesseract-native://capabilities',
            'tesseract-native://desktop-status',
        );

        expect($client->readResource('tesseract-native://capabilities')->content())
            ->toContain('tesseract-native-action')
            ->toContain('tesseract-screen-instrument');

        expect($client->readResource('tesseract-native://desktop-status')->content())
            ->toContain('"available":false')
            ->toContain('"reason":"desktop-unavailable"');

        $controlTools = [
            ['tesseract-native-action', ['actionId' => 'native.navigate', 'arguments' => ['uri' => '/settings']]],
            ['tesseract-screenshot', ['deviceId' => 'emulator-5554', 'platform' => 'android', 'adbPath' => '/sdk/platform-tools/adb']],
            ['tesseract-screen-find', ['query' => 'Counter', 'surface' => 'native']],
            ['tesseract-screen-instrument', ['operation' => 'highlight', 'query' => 'Counter']],
            ['tesseract-profile-snapshot', []],
        ];

        foreach ($controlTools as [$tool, $arguments]) {
            $result = $client->callTool($tool, $arguments);

            expect($result->isError)->toBeFalse()
                ->and($result->text())->toContain('"available":false')
                ->and($result->text())->toContain('"reason":"unpaired"');
        }
    } finally {
        $client->disconnect();
    }
});

it('advertises native desktop action tool annotations as write-capable open-world control', function (): void {
    bootNativeProvider();

    $annotations = app(TesseractNativeActionTool::class)->toArray()['annotations'];

    expect($annotations)->toMatchArray([
        'readOnlyHint' => false,
        'openWorldHint' => true,
        'destructiveHint' => true,
        'idempotentHint' => false,
    ]);

    expect(app(TesseractScreenshotTool::class)->toArray()['annotations'])->toMatchArray([
        'readOnlyHint' => true,
        'openWorldHint' => true,
    ]);

    expect(app(TesseractScreenFindTool::class)->toArray()['annotations'])->toMatchArray([
        'readOnlyHint' => true,
        'openWorldHint' => true,
    ]);

    expect(app(TesseractScreenInstrumentTool::class)->toArray()['annotations'])->toMatchArray([
        'readOnlyHint' => false,
        'openWorldHint' => true,
        'destructiveHint' => false,
        'idempotentHint' => false,
    ]);

    expect(app(TesseractProfileSnapshotTool::class)->toArray()['annotations'])->toMatchArray([
        'readOnlyHint' => true,
        'openWorldHint' => true,
    ]);
});

it('uses the shared tesseractctl config for native desktop control tokens', function (): void {
    bootNativeProvider();

    $previousAppData = getenv('APPDATA');
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tesseractctl-native-'.uniqid();
    $configDir = $root.DIRECTORY_SEPARATOR.'tesseractctl';

    mkdir($configDir, 0777, true);
    $configPath = $configDir.DIRECTORY_SEPARATOR.'config.json';

    file_put_contents($configPath, json_encode([
        'baseUrl' => 'http://127.0.0.1:61235',
        'token' => 'native-shared-token',
    ], JSON_THROW_ON_ERROR));

    putenv('APPDATA='.$root);
    config()->set('tesseract-native.agent_control.token', null);
    config()->set('tesseract-native.agent_control.config_path', $configPath);

    try {
        Http::fake([
            'http://127.0.0.1:61235/agent/v1/actions/profiling.snapshot' => Http::response([
                'ok' => true,
                'data' => ['processes' => [['role' => 'electron-main']]],
                'meta' => [],
            ]),
        ]);

        TesseractNativeServer::tool(TesseractProfileSnapshotTool::class, [])
            ->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:61235/agent/v1/actions/profiling.snapshot'
            && $request->hasHeader('Authorization', 'Bearer native-shared-token'));
    } finally {
        config()->set('tesseract-native.agent_control.config_path', null);
        putenv($previousAppData === false ? 'APPDATA' : 'APPDATA='.$previousAppData);
        @unlink($configPath);
        @rmdir($configDir);
        @rmdir($root);
    }
});

it('mirrors reserved native set-scope through public properties only', function (): void {
    NativeEventHandlers::reset();
    (new ReservedNativeEventRegistrar)->register();

    $component = new class extends NativeComponent
    {
        public int $count = 1;

        public bool $enabled = true;

        public array $errors = [];

        protected string $secret = 'hidden';

        public function __syncProperty(string $property, mixed $value): void
        {
            $this->{$property} = $value;
        }

        public function renderErrorScreen(Throwable $e): void
        {
            $this->errors[] = $e->getMessage();
        }
    };

    NativeEventHandlers::dispatch('tesseract:set-scope', ['property' => 'count', 'value' => '7'], $component);
    NativeEventHandlers::dispatch('tesseract:set-scope', ['property' => 'enabled', 'value' => 'false'], $component);
    NativeEventHandlers::dispatch('tesseract:set-scope', ['property' => 'secret', 'value' => 'changed'], $component);

    expect($component->count)->toBe(7)
        ->and($component->enabled)->toBeFalse()
        ->and($component->errors)->toHaveCount(1)
        ->and($component->errors[0])->toContain('non-public property [$secret]');
});

it('mirrors reserved native calls through public instance methods only', function (): void {
    NativeEventHandlers::reset();
    (new ReservedNativeEventRegistrar)->register();

    $component = new class extends NativeComponent
    {
        public int $count = 1;

        public array $calls = [];

        public array $errors = [];

        public function increment(int $amount, string $label): void
        {
            $this->count += $amount;
            $this->calls[] = $label;
        }

        public static function staticAction(): void
        {
            //
        }

        public function explodeForTest(): void
        {
            throw new RuntimeException('component boom');
        }

        public function renderErrorScreen(Throwable $e): void
        {
            $this->errors[] = $e->getMessage();
        }

        protected function hidden(): void
        {
            $this->count = 99;
        }
    };

    NativeEventHandlers::dispatch('tesseract:call', ['method' => 'increment', 'args' => [4, 'mirror']], $component);
    NativeEventHandlers::dispatch('tesseract:call', ['method' => 'hidden', 'args' => []], $component);
    NativeEventHandlers::dispatch('tesseract:call', ['method' => 'staticAction', 'args' => []], $component);
    NativeEventHandlers::dispatch('tesseract:call', ['method' => '__syncProperty', 'args' => ['count', 99]], $component);
    NativeEventHandlers::dispatch('tesseract:call', ['method' => 'explodeForTest', 'args' => []], $component);

    expect($component->count)->toBe(5)
        ->and($component->calls)->toBe(['mirror'])
        ->and($component->errors)->toHaveCount(4)
        ->and($component->errors[0])->toContain('not a public instance method')
        ->and($component->errors[1])->toContain('not a public instance method')
        ->and($component->errors[2])->toContain('not a public instance method')
        ->and($component->errors[3])->toBe('component boom');
});

it('answers a native debug tool call from faked desktop history and attaches project identity', function (): void {
    bootNativeProvider();

    config()->set('tesseract-native.agent_control.base_url', 'http://127.0.0.1:61230');
    config()->set('tesseract-native.agent_control.config_path', null);
    config()->set('tesseract-native.desktop_loopback_url', 'http://127.0.0.1:61230');

    Http::fake([
        'http://127.0.0.1:61230/api/transport/mcp/debug' => Http::response([
            'crash' => null,
            'summary' => ['errors' => 0, 'logs' => 0, 'requests' => 0, 'interactions' => 0],
            'resolvedSession' => ['sessionId' => 'native-1', 'matchedBy' => 'project'],
            'meta' => ['operation' => 'debug', 'view' => 'summary'],
        ]),
    ]);

    app(TesseractDebugTool::class)->handle(new Request(['runtime' => 'native']));

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(1);

    $request = $recorded->first()[0];

    expect($request->url())->toBe('http://127.0.0.1:61230/api/transport/mcp/debug')
        ->and($request['project']['path'] ?? null)->not->toBeNull()
        ->and($request['runtime'] ?? null)->toBe('native');
});
