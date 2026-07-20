<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Boost\Mcp\Boost;
use Laravel\Mcp\Facades\Mcp;
use Native\Mobile\Testing\TestableComponent;
use Tesseract\NativeCollector\Blade\NativeViewInstrumenter;
use Tesseract\NativeCollector\Commands\CommandExecutor;
use Tesseract\NativeCollector\Commands\CommandPump;
use Tesseract\NativeCollector\Commands\PreCompileCommand;
use Tesseract\NativeCollector\Commands\ReservedNativeEventRegistrar;
use Tesseract\NativeCollector\Console\TesseractNativeMcpCommand;
use Tesseract\NativeCollector\Jobs\PumpTesseractCommands;
use Tesseract\NativeCollector\Mcp\DesktopControlClient;
use Tesseract\NativeCollector\Mcp\DesktopLoopbackResolver;
use Tesseract\NativeCollector\Mcp\McpWorkbenchHistoryClient;
use Tesseract\NativeCollector\Mcp\Support\ProjectIdentity;
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
use Tesseract\NativeCollector\Media\MediaBrowser;
use Tesseract\NativeCollector\Sql\SqlExecutor;
use Tesseract\NativeCollector\Storage\StorageBrowser;
use Tesseract\NativeCollector\Telemetry\EnvelopeFactory;
use Tesseract\NativeCollector\Telemetry\TelemetryForwarder;
use Tesseract\NativeCollector\Testing\ReportingTestableComponent;
use Tesseract\NativeCollector\Tinker\TinkerEvaluator;
use Throwable;

class TesseractNativeCollectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tesseract-native.php', 'tesseract-native');

        $this->registerBoostIntegration();

        $this->app->singleton(DesktopLoopbackResolver::class, static fn ($app): DesktopLoopbackResolver => new DesktopLoopbackResolver(
            $app->make(Pairing::class),
        ));
        $this->app->singleton(DesktopControlClient::class, static fn ($app): DesktopControlClient => new DesktopControlClient(
            $app->make(DesktopLoopbackResolver::class),
        ));
        $this->app->singleton(McpWorkbenchHistoryClient::class, static fn ($app): McpWorkbenchHistoryClient => new McpWorkbenchHistoryClient(
            $app->make(DesktopLoopbackResolver::class),
        ));
        $this->app->singleton(ProjectIdentity::class, static fn ($app): ProjectIdentity => new ProjectIdentity(
            $app->make(Pairing::class),
        ));

        $this->app->singleton(NativeAgent::class, static fn (): NativeAgent => new NativeAgent);
        $this->app->singleton(EnvelopeFactory::class, static fn (): EnvelopeFactory => new EnvelopeFactory);
        $this->app->singleton(TelemetryForwarder::class, static fn ($app): TelemetryForwarder => new TelemetryForwarder(
            $app->make(NativeAgent::class),
            $app->make(EnvelopeFactory::class),
        ));
        $this->app->singleton(SqlExecutor::class, static fn (): SqlExecutor => new SqlExecutor);
        $this->app->singleton(TinkerEvaluator::class, static fn (): TinkerEvaluator => new TinkerEvaluator);
        $this->app->singleton(StorageBrowser::class, static fn (): StorageBrowser => new StorageBrowser);
        $this->app->singleton(MediaBrowser::class, static fn (): MediaBrowser => new MediaBrowser);
        $this->app->singleton(CommandExecutor::class, static fn ($app): CommandExecutor => new CommandExecutor(
            $app->make(SqlExecutor::class),
            $app->make(TinkerEvaluator::class),
            $app->make(StorageBrowser::class),
            $app->make(MediaBrowser::class),
        ));
        $this->app->singleton(CommandPump::class, static fn ($app): CommandPump => new CommandPump(
            $app->make(NativeAgent::class),
            $app->make(CommandExecutor::class),
        ));
        $this->app->singleton(ReservedNativeEventRegistrar::class, static fn (): ReservedNativeEventRegistrar => new ReservedNativeEventRegistrar);

        if ($this->app->runningInConsole()) {
            $this->commands([
                PreCompileCommand::class,
                TesseractNativeMcpCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/tesseract-native.php' => config_path('tesseract-native.php'),
        ], 'tesseract-native-config');

        $this->disableForTestRuns();
        $this->registerMcpServer();
        $this->registerTestHarness();

        if (! (bool) config('tesseract-native.enabled', true)) {
            return;
        }

        $this->app->make(TelemetryForwarder::class)->subscribe(
            $this->app->make(Dispatcher::class),
        );

        $this->app->make(ReservedNativeEventRegistrar::class)->register();

        if ((bool) config('tesseract-native.instrumentation.native_views', true)) {
            NativeViewInstrumenter::register();
        }

        $this->app->booted(function (): void {
            $this->igniteAgent();
            $this->serviceCommands();
            $this->app->make(TelemetryForwarder::class)->broadcastRoutes();
        });
    }

    /**
     * Resolve the collector's master gate for test runs by flipping the
     * `enabled` config itself, so every reader — this provider's boot gate
     * and any future consumer — sees the effective value. Igniting the
     * transports with no device attached blocks waiting on the agent and
     * hangs the suite, so tests disable the collector by default;
     * `enabled_during_tests` opts a run back in, and the desktop test
     * runner's explicit TESSERACT_NATIVE_ENABLED=false continues to work
     * unchanged.
     */
    protected function disableForTestRuns(): void
    {
        if (! $this->app->runningUnitTests()) {
            return;
        }

        if ((bool) config('tesseract-native.enabled_during_tests', false)) {
            return;
        }

        config(['tesseract-native.enabled' => false]);
    }

    /**
     * Route the NativePHP test harness through the reporting proxy when a
     * test run asks for step reporting (TESSERACT_TEST_REPORT names the
     * NDJSON output file). Registered before the collector's enabled gate —
     * the reporter belongs to the test workflow, not the device agent — and
     * inert otherwise: test()/visit() construct the vendor harness exactly
     * as before.
     */
    protected function registerTestHarness(): void
    {
        $report = getenv('TESSERACT_TEST_REPORT');

        if ($report === false || $report === '') {
            return;
        }

        if (method_exists(TestableComponent::class, 'useHarness')) {
            TestableComponent::useHarness(ReportingTestableComponent::class);
        }
    }

    /**
     * Register the native MCP history server so an AI agent on the dev machine
     * can read back captured device sessions. Mirrors the collector: skipped
     * when Boost is present (the tools ride Boost's existing connection
     * instead), and best-effort so a broken MCP install never aborts boot.
     */
    protected function registerMcpServer(): void
    {
        if (! (bool) config('tesseract-native.mcp.enabled', true)) {
            return;
        }

        if (! class_exists(Mcp::class)) {
            return;
        }

        if ($this->boostIntegrationActive()) {
            return;
        }

        $handle = trim((string) config('tesseract-native.mcp.handle', 'tesseract-native'));

        if ($handle === '') {
            return;
        }

        try {
            Mcp::local($handle, TesseractNativeServer::class);
        } catch (Throwable) {
            //
        }
    }

    /**
     * When Laravel Boost is installed, push the native Tesseract MCP tools into
     * Boost's include list so they surface through the existing
     * `mcp__laravel-boost__*` connection instead of a second MCP entry.
     */
    protected function registerBoostIntegration(): void
    {
        if (! $this->boostIntegrationActive()) {
            return;
        }

        $tools = [
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
        ];

        $existing = (array) config('boost.mcp.tools.include', []);

        config([
            'boost.mcp.tools.include' => array_values(array_unique([...$existing, ...$tools])),
        ]);
    }

    protected function boostIntegrationActive(): bool
    {
        if (! (bool) config('tesseract-native.boost.enabled', true)) {
            return false;
        }

        return class_exists(Boost::class) && $this->boostMcpCommandAvailable();
    }

    protected function boostMcpCommandAvailable(): bool
    {
        try {
            if (! $this->app->bound(Kernel::class)) {
                return false;
            }

            $kernel = $this->app->make(Kernel::class);

            if (method_exists($kernel, 'getArtisan') && $kernel->getArtisan()->has('boost:mcp')) {
                return true;
            }

            return array_key_exists('boost:mcp', $kernel->all());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Keep host commands flowing two ways:
     *
     *  - The primary, idle-safe path: a self-perpetuating queued job the shell's
     *    queue worker runs in a PHP runtime off the render loop.
     *  - A fallback tick on this render dispatch, in case the queue worker isn't
     *    available - drains anything buffered while the developer interacts.
     *
     * Both drain the same buffer atomically, so running both is harmless.
     */
    protected function serviceCommands(): void
    {
        // Force a fresh chain on launch: a stale heartbeat from an unclean prior
        // exit would otherwise leave the pump dormant (so SQL/storage don't load)
        // until the app is relaunched once more. restartChain clears it exactly
        // once per runtime, then a render-path tick drains anything buffered.
        PumpTesseractCommands::restartChain();

        try {
            $this->app->make(CommandPump::class)->tick();
        } catch (Throwable) {
            //
        }
    }

    /**
     * Ask the native agent to open the desktop session and start its transport.
     *
     * There is no runtime native boot hook for a plugin beyond `init_function`,
     * so PHP is the reliable ignition: the persistent runtime boots Laravel at
     * app launch, and this fires `Tesseract.Connect`. The call is idempotent on
     * the agent side, so the build-time `init_function` and this ignition can
     * both run without racing.
     *
     * The connection details come from the pairing file the desktop delivers
     * for this launch - the per-launch relay port and, critically,
     * `project_id`, which the desktop matches against to clear the "launching"
     * state. Config values are only a fallback for an unpaired run.
     */
    protected function igniteAgent(): void
    {
        try {
            /** @var NativeAgent $agent */
            $agent = $this->app->make(NativeAgent::class);

            if (! $agent->isAvailable()) {
                return;
            }

            $pairing = $this->app->make(Pairing::class)->read() ?? [];
            $projectPath = is_string($pairing['project_path'] ?? null)
                ? trim((string) $pairing['project_path'])
                : '';

            // Never advertise the device sandbox base_path() as the host
            // project — desktop local source mapping would join against a
            // path that does not exist on the workbench machine.
            if ($projectPath === '') {
                return;
            }

            $agent->connect([
                'appName' => (string) config('app.name', 'Laravel'),
                'appUrl' => (string) config('app.url', ''),
                'projectKey' => (string) ($pairing['project_id'] ?? sha1((string) base_path())),
                'projectPath' => $projectPath,
                'host' => (string) ($pairing['host'] ?? config('tesseract-native.transport.host', '127.0.0.1')),
                'relayPort' => (int) ($pairing['transport_port'] ?? config('tesseract-native.transport.relay_port', 61230)),
                'relayUrl' => (string) ($pairing['relay_url'] ?? ''),
                'capabilities' => (array) config('tesseract-native.capabilities', []),
            ]);
        } catch (Throwable) {
            //
        }
    }
}
