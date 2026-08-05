<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;
use Tesseract\NativeCollector\Console\TesseractNativeMcpCommand;
use Tesseract\NativeCollector\Mcp\DesktopLoopbackResolver;
use Tesseract\NativeCollector\Mcp\TesseractNativeServer;
use Tesseract\NativeCollector\Mcp\Tools\TesseractDebugTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractViewTreeTool;
use Tesseract\NativeCollector\Pairing;
use Tesseract\NativeCollector\Support\NativeRouteCollector;
use Tesseract\NativeCollector\TesseractNativeCollectorServiceProvider;

function bootNativeToolsProvider(): TesseractNativeCollectorServiceProvider
{
    $provider = new TesseractNativeCollectorServiceProvider(app());
    $provider->register();

    return $provider;
}

function fakeNativePairing(?array $data): Pairing
{
    return new class($data) extends Pairing
    {
        public function __construct(private ?array $data) {}

        public function read(): ?array
        {
            return $this->data;
        }
    };
}

/*
 * Task 1 - the loopback discovery ladder.
 */

it('prefers an explicit desktop_loopback_url override over pairing', function (): void {
    config()->set('tesseract-native.desktop_loopback_url', 'http://desktop.override:9999');

    $resolver = new DesktopLoopbackResolver(fakeNativePairing([
        'relay_url' => 'http://127.0.0.1:7001',
    ]));

    expect($resolver->resolve())->toMatchArray([
        'url' => 'http://desktop.override:9999',
        'source' => 'config',
    ]);
});

it('falls back to the pairing relay_url, matching the envelope transport', function (): void {
    config()->set('tesseract-native.desktop_loopback_url', null);

    $resolver = new DesktopLoopbackResolver(fakeNativePairing([
        'project_id' => 'native-1',
        'relay_url' => 'http://127.0.0.1:7001',
    ]));

    expect($resolver->resolve())->toMatchArray([
        'url' => 'http://127.0.0.1:7001',
        'source' => 'pairing',
        'pairingPresent' => true,
    ]);
});

it('derives the pairing host and transport_port when no relay_url is present', function (): void {
    config()->set('tesseract-native.desktop_loopback_url', null);

    $resolver = new DesktopLoopbackResolver(fakeNativePairing([
        'host' => '127.0.0.1',
        'transport_port' => 7002,
    ]));

    expect($resolver->resolve())->toMatchArray([
        'url' => 'http://127.0.0.1:7002',
        'source' => 'pairing',
    ]);
});

it('falls back to the configured transport defaults when nothing else resolves', function (): void {
    config()->set('tesseract-native.desktop_loopback_url', null);
    config()->set('tesseract-native.transport.host', '127.0.0.1');
    config()->set('tesseract-native.transport.relay_port', 61230);

    $resolver = new DesktopLoopbackResolver(fakeNativePairing(null));

    expect($resolver->resolve())->toMatchArray([
        'url' => 'http://127.0.0.1:61230',
        'source' => 'default',
        'pairingPresent' => false,
    ]);
});

it('skips stale shared native agent config and falls back to the desktop descriptor', function (): void {
    config()->set('tesseract-native.desktop_loopback_url', null);

    $resolver = new class(fakeNativePairing(null)) extends DesktopLoopbackResolver
    {
        /**
         * @return array<string, mixed>
         */
        protected function sharedAgentConfig(): array
        {
            return ['baseUrl' => 'http://127.0.0.1:1'];
        }

        protected function descriptorBaseUrl(): ?string
        {
            return 'http://127.0.0.1:52707';
        }

        protected function agentBaseUrlReachable(string $baseUrl): bool
        {
            return false;
        }
    };

    expect($resolver->resolve())->toMatchArray([
        'url' => 'http://127.0.0.1:52707',
        'source' => 'descriptor',
        'pairingPresent' => false,
    ]);
});

it('names what to check when the desktop is unreachable and no pairing exists', function (): void {
    config()->set('tesseract-native.desktop_loopback_url', null);

    $hint = (new DesktopLoopbackResolver(fakeNativePairing(null)))->reachabilityHint();

    expect($hint)
        ->toContain('Tesseract Desktop is running')
        ->toContain('.tesseract/pairing.json')
        ->toContain('TESSERACT_NATIVE_DESKTOP_LOOPBACK_URL');
});

it('folds the reachability hint into a desktop-unavailable tool error', function (): void {
    bootNativeToolsProvider();

    config()->set('tesseract-native.desktop_loopback_url', 'http://127.0.0.1:61230');

    Http::fake(function (): void {
        throw new ConnectionException('Connection refused');
    });

    TesseractNativeServer::tool(TesseractDebugTool::class, [])
        ->assertHasErrors(['Tesseract Desktop is running']);
});

/*
 * Task 2 - tesseract-screens (in-process route reflection).
 */

it('returns an empty screen list when the native shell has no routes', function (): void {
    $collector = new NativeRouteCollector;

    expect($collector->available())->toBeTrue()
        ->and($collector->routes())->toBe([]);
});

/*
 * Task 2 - tesseract-view-tree (desktop-loopback read over the requests stream).
 */

it('reads the current screen view tree through the requests history stream', function (): void {
    bootNativeToolsProvider();

    config()->set('tesseract-native.desktop_loopback_url', 'http://127.0.0.1:61230');

    Http::fake([
        'http://127.0.0.1:61230/api/transport/mcp/search' => Http::response([
            'kind' => 'requests',
            'entries' => [
                ['id' => 'nav-1', 'method' => 'NAVIGATE', 'route' => '/home'],
            ],
            'counts' => ['matched' => 1, 'returned' => 1, 'totalAvailable' => 1],
            'nextCursor' => null,
        ]),
    ]);

    TesseractNativeServer::tool(TesseractViewTreeTool::class, [])
        ->assertOk();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'http://127.0.0.1:61230/api/transport/mcp/search'
            && ($request['kind'] ?? null) === 'requests'
            && ($request['project']['path'] ?? null) !== null;
    });
});

/*
 * Task 3 - onboarding command.
 */

it('prints a standalone .mcp.json entry when Boost is disabled', function (): void {
    bootNativeToolsProvider();

    config()->set('tesseract-native.boost.enabled', false);
    config()->set('tesseract-native.mcp.handle', 'tesseract-native');

    app()->make(ConsoleKernel::class)->registerCommand(app()->make(TesseractNativeMcpCommand::class));

    $exit = Artisan::call('tesseract-native:mcp', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('mcpServers')
        ->and($output)->toContain('mcp:start')
        ->and($output)->toContain('tesseract-native');
});

it('prints a runnable native stdio command including artisan', function (): void {
    bootNativeToolsProvider();

    config()->set('tesseract-native.boost.enabled', false);
    config()->set('tesseract-native.mcp.handle', 'tesseract-native');

    app()->make(ConsoleKernel::class)->registerCommand(app()->make(TesseractNativeMcpCommand::class));

    $exit = Artisan::call('tesseract-native:mcp');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain(PHP_BINARY.' '.base_path('artisan').' mcp:start tesseract-native');
});

it('falls back to the standalone native entry when Boost is configured but its MCP command is unavailable', function (): void {
    bootNativeToolsProvider();

    config()->set('tesseract-native.boost.enabled', true);
    config()->set('tesseract-native.mcp.handle', 'tesseract-native');

    $command = new class extends TesseractNativeMcpCommand
    {
        protected function boostMcpCommandAvailable(): bool
        {
            return false;
        }
    };

    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute(['--json' => true]))->toBe(0)
        ->and($tester->getDisplay())->toContain('mcp:start')
        ->and($tester->getDisplay())->toContain('tesseract-native')
        ->and($tester->getDisplay())->not->toContain('boost:mcp');
});

it('prints the laravel-boost entry when Boost hosts the tools', function (): void {
    bootNativeToolsProvider();

    config()->set('tesseract-native.boost.enabled', true);

    $command = new class extends TesseractNativeMcpCommand
    {
        protected function boostMcpCommandAvailable(): bool
        {
            return true;
        }
    };

    $command->setLaravel(app());
    $tester = new CommandTester($command);

    expect($tester->execute(['--json' => true]))->toBe(0)
        ->and($tester->getDisplay())->toContain('laravel-boost')
        ->and($tester->getDisplay())->toContain('boost:mcp');
});
