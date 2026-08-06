<?php

declare(strict_types=1);

use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tesseract\NativeCollector\NativeAgent;
use Tesseract\NativeCollector\TesseractNativeCollectorServiceProvider;
use Tesseract\NativeCollector\Testing\ReportingTestableComponent;
use Tesseract\NativeCollector\Testing\TestStepReporter;

class ReportingHarnessIntegrationScreen extends NativeComponent
{
    public function render(): Column
    {
        return Column::make();
    }
}

it('merges its configuration without starting the native transport', function (): void {
    expect(config('tesseract-native.enabled'))->toBeFalse()
        ->and(app(NativeAgent::class))->toBeInstanceOf(NativeAgent::class);
});

it('degrades safely when the native bridge is unavailable', function (): void {
    $agent = app(NativeAgent::class);

    expect($agent->isAvailable())->toBeFalse()
        ->and($agent->ingest([]))->toBeTrue()
        ->and($agent->status())->toBeNull();
});

it('hard-disables runtime collection outside Laravel debug mode', function (): void {
    config(['app.debug' => false, 'tesseract-native.enabled' => true]);

    $provider = new class(app()) extends TesseractNativeCollectorServiceProvider
    {
        public function applyDebugGate(): void
        {
            $this->disableOutsideDebugMode();
        }
    };
    $provider->applyDebugGate();

    expect(config('tesseract-native.enabled'))->toBeFalse();
});

it('allows the package switch to remain enabled in Laravel debug mode', function (): void {
    config(['app.debug' => true, 'tesseract-native.enabled' => true]);

    $provider = new class(app()) extends TesseractNativeCollectorServiceProvider
    {
        public function applyDebugGate(): void
        {
            $this->disableOutsideDebugMode();
        }
    };
    $provider->applyDebugGate();

    expect(config('tesseract-native.enabled'))->toBeTrue();
});

it('does not start the perpetual command pump on a synchronous queue', function (): void {
    config([
        'queue.default' => 'sync',
        'queue.connections.sync.driver' => 'sync',
    ]);

    $provider = new class(app()) extends TesseractNativeCollectorServiceProvider
    {
        public function canStartBackgroundCommandPump(): bool
        {
            return $this->supportsBackgroundCommandPump();
        }
    };

    expect($provider->canStartBackgroundCommandPump())->toBeFalse();
});

it('allows the perpetual command pump on a durable queue', function (): void {
    config([
        'queue.default' => 'database',
        'queue.connections.database.driver' => 'database',
    ]);

    $provider = new class(app()) extends TesseractNativeCollectorServiceProvider
    {
        public function canStartBackgroundCommandPump(): bool
        {
            return $this->supportsBackgroundCommandPump();
        }
    };

    expect($provider->canStartBackgroundCommandPump())->toBeTrue();
});

it('installs the reporting harness when a desktop test report is requested', function (): void {
    $reportPath = tempnam(sys_get_temp_dir(), 'tesseract-report-');

    expect($reportPath)->toBeString();

    putenv('TESSERACT_TEST_REPORT='.$reportPath);
    TestableComponent::useHarness(null);

    $provider = new class(app()) extends TesseractNativeCollectorServiceProvider
    {
        public function installReportingHarness(): void
        {
            $this->registerTestHarness();
        }
    };

    try {
        if (! function_exists('nativephp_runtime_flags')) {
            $runtimeSource = dirname((new ReflectionClass(TestableComponent::class))->getFileName(), 2);
            require_once $runtimeSource.'/jump_bridge_functions.php';
        }

        $provider->installReportingHarness();
        $harness = Native::test(ReportingHarnessIntegrationScreen::class);
        $span = TestStepReporter::begin('instruction', 'set', ['count', 5], [
            'kind' => 'native.set-scope',
            'payload' => [
                'property' => 'count',
                'value' => 5,
            ],
        ]);
        TestStepReporter::finish($span, 'passed', null);
        $events = array_values(array_filter(array_map(
            static fn (string $line): mixed => json_decode($line, true),
            file($reportPath, FILE_IGNORE_NEW_LINES) ?: [],
        ), 'is_array'));

        expect($harness)->toBeInstanceOf(ReportingTestableComponent::class)
            ->and($events)->toHaveCount(4)
            ->and($events[0])->toMatchArray([
                'phase' => 'entry',
                'method' => 'test',
                'result' => 'running',
            ])
            ->and($events[1])->toMatchArray([
                'result' => 'passed',
            ])
            ->and($events[2])->toMatchArray([
                'phase' => 'instruction',
                'method' => 'set',
                'result' => 'running',
                'mirror' => [
                    'kind' => 'native.set-scope',
                    'payload' => [
                        'property' => 'count',
                        'value' => 5,
                    ],
                ],
            ])
            ->and($events[3])->toMatchArray([
                'result' => 'passed',
                'mirror' => [
                    'kind' => 'native.set-scope',
                    'payload' => [
                        'property' => 'count',
                        'value' => 5,
                    ],
                ],
            ]);
    } finally {
        TestableComponent::useHarness(null);
        putenv('TESSERACT_TEST_REPORT');
        $reporter = new ReflectionClass(TestStepReporter::class);

        foreach ([
            'enabled' => null,
            'path' => null,
            'depth' => 0,
            'identity' => null,
            'seq' => [],
        ] as $property => $value) {
            $reporter->getProperty($property)->setValue(null, $value);
        }

        @unlink($reportPath);
    }
});
