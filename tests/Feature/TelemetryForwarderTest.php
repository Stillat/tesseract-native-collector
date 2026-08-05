<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Runtime\ComponentContext;
use Native\Mobile\Edge\Runtime\ComponentPublished;
use Native\Mobile\Edge\Runtime\Dispatch;
use Native\Mobile\Edge\Runtime\DispatchFinished;
use Native\Mobile\Edge\Runtime\DispatchKind;
use Native\Mobile\Edge\Runtime\DispatchStarting;
use Native\Mobile\Edge\RuntimeObservers;
use Tesseract\NativeCollector\NativeAgent;
use Tesseract\NativeCollector\Telemetry\EnvelopeFactory;
use Tesseract\NativeCollector\Telemetry\FrameworkEventGate;
use Tesseract\NativeCollector\Telemetry\RuntimeHookAdapter;
use Tesseract\NativeCollector\Telemetry\TelemetryForwarder;

class NativeTelemetryOrderShippedListener
{
    public function handle(mixed ...$arguments): void {}
}

class TelemetryForwarderHarnessComponent extends NativeComponent
{
    public int $count = 0;

    public int $scans = 0;

    public function render(): Column
    {
        return Column::make();
    }
}

function publishTelemetryComponent(NativeComponent $component, string $uri): void
{
    RuntimeObservers::componentPublished(new ComponentPublished(
        new ComponentContext($component, $uri, 1),
    ));
}

function telemetryDispatch(
    NativeComponent $component,
    int $id,
    string $uri,
    DispatchKind $kind,
    string $method,
    ?string $event = null,
    array $payload = [],
): Dispatch {
    return new Dispatch(
        id: $id,
        context: new ComponentContext($component, $uri, 1),
        kind: $kind,
        method: $method,
        arguments: [],
        eventType: $kind === DispatchKind::Interaction ? 0 : null,
        callbackId: $kind === DispatchKind::Interaction ? $id : null,
        event: $event,
        payload: $payload,
    );
}

afterEach(function (): void {
    RuntimeHookAdapter::reset();
    RuntimeObservers::reset();
});

it('correlates handler telemetry to the interaction id pre-allocated by the willDispatch hook', function (): void {
    $component = new TelemetryForwarderHarnessComponent;

    $agent = new class extends NativeAgent
    {
        /** @var array<int, array<string, mixed>> */
        public array $ingested = [];

        public function ingest(array $envelopes): bool
        {
            $this->ingested = array_merge($this->ingested, $envelopes);

            return true;
        }
    };

    $forwarder = new TelemetryForwarder($agent, new EnvelopeFactory);
    $dispatcher = new Dispatcher(app());
    $forwarder->subscribe($dispatcher);

    publishTelemetryComponent($component, '/home');

    $navigationCorrelation = collect($agent->ingested)
        ->first(static fn (array $envelope): bool => str_starts_with(
            $envelope['correlation']['requestId'] ?? '',
            'nav-',
        ))['correlation'];

    $dispatch = telemetryDispatch($component, 7, '/home', DispatchKind::Interaction, 'increment');
    RuntimeObservers::dispatchStarting(new DispatchStarting($dispatch));

    $startedEnvelope = collect($agent->ingested)->first(
        static fn (array $envelope): bool => $envelope['kind'] === 'server.request.started'
            && str_starts_with($envelope['correlation']['requestId'] ?? '', 'int-'),
    );

    $agent->ingested = [];
    $dispatcher->dispatch(new QueryExecuted('select * from "orders"', [], 1.0, DB::connection()));

    $queryEnvelopes = array_values(array_filter(
        $agent->ingested,
        static fn (array $envelope): bool => $envelope['kind'] === 'server.query.executed',
    ));

    expect($queryEnvelopes)->toHaveCount(1);

    $interactionCorrelation = $queryEnvelopes[0]['correlation'];
    $interactionId = $interactionCorrelation['requestId'];

    expect($interactionId)->toStartWith('int-')
        ->and($interactionCorrelation['traceId'])->toBe($navigationCorrelation['traceId'])
        ->and($interactionCorrelation['parentRequestId'])->toBe($navigationCorrelation['requestId'])
        ->and($startedEnvelope['correlation'])->toBe($interactionCorrelation);

    $agent->ingested = [];
    $component->count = 1;
    RuntimeObservers::dispatchFinished(new DispatchFinished($dispatch, 2.0));

    $finishedCorrelations = array_map(
        static fn (array $envelope): array => $envelope['correlation'],
        array_values(array_filter(
            $agent->ingested,
            static fn (array $envelope): bool => $envelope['kind'] === 'server.request.finished',
        )),
    );

    expect($finishedCorrelations)->toBe([$interactionCorrelation])
        ->and(collect($agent->ingested)->contains(
            static fn (array $envelope): bool => $envelope['kind'] === 'server.request.started',
        ))->toBeFalse();

    $agent->ingested = [];
    $dispatcher->dispatch(new QueryExecuted('select * from "orders"', [], 1.0, DB::connection()));

    expect($agent->ingested[0]['correlation'])->toBe($navigationCorrelation);

    $agent->ingested = [];
    $fallbackDispatch = telemetryDispatch($component, 8, '/home', DispatchKind::Interaction, 'increment');
    RuntimeObservers::dispatchFinished(new DispatchFinished($fallbackDispatch, 1.0));

    $fallbackCorrelation = $agent->ingested[0]['correlation'];
    $fallbackId = $fallbackCorrelation['requestId'];

    expect($fallbackId)->toStartWith('int-')
        ->and($fallbackId)->not->toBe($interactionId)
        ->and($fallbackCorrelation['traceId'])->toBe($navigationCorrelation['traceId'])
        ->and($fallbackCorrelation['parentRequestId'])->toBe($navigationCorrelation['requestId']);

});

it('correlates native event handler telemetry and restores the navigation context afterward', function (): void {
    $component = new TelemetryForwarderHarnessComponent;

    $agent = new class extends NativeAgent
    {
        /** @var array<int, array<string, mixed>> */
        public array $ingested = [];

        public function ingest(array $envelopes): bool
        {
            $this->ingested = array_merge($this->ingested, $envelopes);

            return true;
        }
    };
    $dispatcher = new Dispatcher(app());
    (new TelemetryForwarder($agent, new EnvelopeFactory))->subscribe($dispatcher);

    publishTelemetryComponent($component, '/scanner');

    $navigationCorrelation = collect($agent->ingested)
        ->first(static fn (array $envelope): bool => str_starts_with(
            $envelope['correlation']['requestId'] ?? '',
            'nav-',
        ))['correlation'];

    $dispatch = telemetryDispatch(
        $component,
        1,
        '/scanner',
        DispatchKind::Native,
        'handleScan',
        'Native\\Mobile\\Events\\Scanner\\CodeScanned',
        ['data' => 'secret', 'format' => 'QR_CODE'],
    );
    RuntimeObservers::dispatchStarting(new DispatchStarting($dispatch));

    $started = collect($agent->ingested)->first(
        static fn (array $envelope): bool => $envelope['kind'] === 'server.request.started'
            && str_starts_with($envelope['correlation']['requestId'] ?? '', 'evt-'),
    );

    $agent->ingested = [];
    $dispatcher->dispatch(new QueryExecuted('select * from "scans"', [], 1.0, DB::connection()));

    expect($started['correlation']['traceId'])->toBe($navigationCorrelation['traceId'])
        ->and($started['correlation']['parentRequestId'])->toBe($navigationCorrelation['requestId'])
        ->and($agent->ingested[0]['correlation'])->toBe($started['correlation']);

    $agent->ingested = [];
    $component->scans = 1;
    RuntimeObservers::dispatchFinished(new DispatchFinished($dispatch, 1.5));

    $finished = collect($agent->ingested)->firstWhere('kind', 'server.request.finished');

    expect($finished['correlation'])->toBe($started['correlation'])
        ->and(collect($agent->ingested)->contains(
            static fn (array $envelope): bool => $envelope['kind'] === 'server.request.started',
        ))->toBeFalse();

    $agent->ingested = [];
    $dispatcher->dispatch(new QueryExecuted('select * from "scans"', [], 1.0, DB::connection()));

    expect($agent->ingested[0]['correlation'])->toBe($navigationCorrelation);

});

it('contains native event telemetry failures and restores the prior correlation', function (): void {
    $component = new TelemetryForwarderHarnessComponent;

    $agent = new class extends NativeAgent
    {
        /** @var array<int, array<string, mixed>> */
        public array $ingested = [];

        public function ingest(array $envelopes): bool
        {
            $this->ingested = array_merge($this->ingested, $envelopes);

            return true;
        }
    };
    $factory = new class extends EnvelopeFactory
    {
        public bool $failNativeEvent = false;

        public function nativeEvent(
            array $event,
            string $requestId,
            ?string $traceId = null,
            ?string $parentRequestId = null,
        ): array {
            if ($this->failNativeEvent) {
                throw new RuntimeException('envelope failed');
            }

            return parent::nativeEvent($event, $requestId, $traceId, $parentRequestId);
        }
    };
    $dispatcher = new Dispatcher(app());
    (new TelemetryForwarder($agent, $factory))->subscribe($dispatcher);

    publishTelemetryComponent($component, '/scanner');
    $navigationCorrelation = collect($agent->ingested)
        ->first(static fn (array $envelope): bool => str_starts_with(
            $envelope['correlation']['requestId'] ?? '',
            'nav-',
        ))['correlation'];
    $dispatch = telemetryDispatch(
        $component,
        1,
        '/scanner',
        DispatchKind::Native,
        'handleScan',
        'Native\\Mobile\\Events\\Scanner\\CodeScanned',
    );
    RuntimeObservers::dispatchStarting(new DispatchStarting($dispatch));
    $factory->failNativeEvent = true;
    RuntimeObservers::dispatchFinished(new DispatchFinished($dispatch, 1.0));

    $factory->failNativeEvent = false;
    $agent->ingested = [];
    $dispatcher->dispatch(new QueryExecuted('select 1', [], 1.0, DB::connection()));

    expect($agent->ingested[0]['correlation'])->toBe($navigationCorrelation);
});

it('keeps correlation correct when an interaction handler dispatches another interaction', function (): void {
    $component = new TelemetryForwarderHarnessComponent;

    $agent = new class extends NativeAgent
    {
        /** @var array<int, array<string, mixed>> */
        public array $ingested = [];

        public function ingest(array $envelopes): bool
        {
            $this->ingested = array_merge($this->ingested, $envelopes);

            return true;
        }
    };

    $forwarder = new TelemetryForwarder($agent, new EnvelopeFactory);
    $dispatcher = new Dispatcher(app());
    $forwarder->subscribe($dispatcher);

    publishTelemetryComponent($component, '/home');

    $navigationCorrelation = collect($agent->ingested)
        ->first(static fn (array $envelope): bool => str_starts_with(
            $envelope['correlation']['requestId'] ?? '',
            'nav-',
        ))['correlation'];

    $correlationOf = static fn (QueryExecuted $q): array => value(function () use ($agent, $dispatcher, $q) {
        $agent->ingested = [];
        $dispatcher->dispatch($q);

        return $agent->ingested[0]['correlation'];
    });

    $outer = telemetryDispatch($component, 1, '/home', DispatchKind::Interaction, 'outer');
    RuntimeObservers::dispatchStarting(new DispatchStarting($outer));
    $outerCorrelation = $correlationOf(new QueryExecuted('select 1', [], 1.0, DB::connection()));
    $outerId = $outerCorrelation['requestId'];
    expect($outerId)->toStartWith('int-')
        ->and($outerCorrelation['traceId'])->toBe($navigationCorrelation['traceId'])
        ->and($outerCorrelation['parentRequestId'])->toBe($navigationCorrelation['requestId']);

    $inner = telemetryDispatch($component, 2, '/home', DispatchKind::Interaction, 'inner');
    RuntimeObservers::dispatchStarting(new DispatchStarting($inner));
    $innerCorrelation = $correlationOf(new QueryExecuted('select 2', [], 1.0, DB::connection()));
    $innerId = $innerCorrelation['requestId'];
    expect($innerId)->toStartWith('int-')
        ->and($innerId)->not->toBe($outerId)
        ->and($innerCorrelation['traceId'])->toBe($navigationCorrelation['traceId'])
        ->and($innerCorrelation['parentRequestId'])->toBe($outerId);
    RuntimeObservers::dispatchFinished(new DispatchFinished($inner, 1.0));

    expect($correlationOf(new QueryExecuted('select 3', [], 1.0, DB::connection())))
        ->toBe($outerCorrelation);

    RuntimeObservers::dispatchFinished(new DispatchFinished($outer, 1.0));
    expect($correlationOf(new QueryExecuted('select 4', [], 1.0, DB::connection())))
        ->toBe($navigationCorrelation);
});

it('labels raw class listeners as Class@method and omits the wildcard self-entry', function (): void {
    $agent = new class extends NativeAgent
    {
        /** @var array<int, array<string, mixed>> */
        public array $ingested = [];

        public function ingest(array $envelopes): bool
        {
            $this->ingested = array_merge($this->ingested, $envelopes);

            return true;
        }
    };

    $forwarder = new TelemetryForwarder($agent, new EnvelopeFactory);
    $dispatcher = new Dispatcher(app());
    $forwarder->subscribe($dispatcher);

    $dispatcher->listen('order.shipped', [NativeTelemetryOrderShippedListener::class, 'handle']);

    $dispatcher->dispatch('order.shipped', ['order-1']);

    $frameworkEnvelopes = array_values(array_filter(
        $agent->ingested,
        static fn (array $envelope): bool => $envelope['kind'] === 'server.event.dispatched',
    ));

    expect($frameworkEnvelopes)->toHaveCount(1)
        ->and($frameworkEnvelopes[0]['payload']['listeners'])->toBe([NativeTelemetryOrderShippedListener::class.'@handle']);
});

it('resets the framework event bucket on a timer when no request id is ever tracked', function (): void {
    $gate = new class extends FrameworkEventGate
    {
        public int $second = 0;

        protected function nowSeconds(): int
        {
            return $this->second;
        }
    };

    $accepted = 0;

    for ($i = 0; $i < 120; $i++) {
        $gate->second = intdiv($i, 10);

        if ($gate->accepts('App\Events\Ping', [], null)) {
            $accepted++;
        }
    }

    expect($accepted)->toBe(100);

    $gate->second = 30;

    expect($gate->accepts('App\Events\Ping', [], null))->toBeFalse();

    $gate->second = 62;

    expect($gate->accepts('App\Events\Ping', [], null))->toBeTrue();
});

it('opens a fresh bucket when an interaction request id is observed', function (): void {
    $gate = new class extends FrameworkEventGate
    {
        public int $second = 0;

        protected function nowSeconds(): int
        {
            return $this->second;
        }
    };

    for ($i = 0; $i < 110; $i++) {
        $gate->second = intdiv($i, 10);
        $gate->accepts('App\Events\Ping', [], 'nav-1-aaaaaaaa');
    }

    $gate->second = 12;

    expect($gate->accepts('App\Events\Ping', [], 'nav-1-aaaaaaaa'))->toBeFalse()
        ->and($gate->accepts('App\Events\Ping', [], 'int-1-deadbeef'))->toBeTrue()
        ->and($gate->accepts('App\Events\Ping', [], 'nav-1-aaaaaaaa'))->toBeTrue();
});

it('matches suppressed tables as whole identifiers, not substrings', function (): void {
    $gate = new FrameworkEventGate;

    expect($gate->isCollectorInfrastructureQuery('select * from analytics_jobs'))->toBeFalse()
        ->and($gate->isCollectorInfrastructureQuery('select * from "jobs"'))->toBeTrue()
        ->and($gate->isCollectorInfrastructureQuery("select 'cache' as label from users"))->toBeFalse()
        ->and($gate->isCollectorInfrastructureQuery("select 'theme.cache' from settings"))->toBeFalse()
        ->and($gate->isCollectorInfrastructureQuery('select * from jobs where queue = ?'))->toBeTrue()
        ->and($gate->isCollectorInfrastructureQuery('delete from `cache_locks` where `key` = ?'))->toBeTrue()
        ->and($gate->isCollectorInfrastructureQuery('select * from main.jobs'))->toBeTrue()
        ->and($gate->isCollectorInfrastructureQuery('insert into jobs (queue, payload) values (?, ?)'))->toBeTrue();
});

it('survives payload objects with throwing magic getters (the Container during boot)', function (): void {
    $agent = new class extends NativeAgent
    {
        /** @var array<int, array<string, mixed>> */
        public array $ingested = [];

        public function ingest(array $envelopes): bool
        {
            $this->ingested = array_merge($this->ingested, $envelopes);

            return true;
        }
    };

    $forwarder = new TelemetryForwarder($agent, new EnvelopeFactory);
    $dispatcher = new Dispatcher(app());
    $forwarder->subscribe($dispatcher);

    $dispatcher->dispatch('bootstrapped: Illuminate\Foundation\Bootstrap\BootProviders', [app()]);

    $hostile = new class
    {
        public function __get(string $name): mixed
        {
            throw new RuntimeException("magic getter invoked for {$name}");
        }
    };

    $dispatcher->dispatch('App\Events\CustomThing', [$hostile]);

    $frameworkEnvelopes = array_values(array_filter(
        $agent->ingested,
        static fn (array $envelope): bool => $envelope['kind'] === 'server.event.dispatched',
    ));

    expect($frameworkEnvelopes)->toHaveCount(1)
        ->and($frameworkEnvelopes[0]['payload']['name'])->toBe('App\Events\CustomThing');
});
