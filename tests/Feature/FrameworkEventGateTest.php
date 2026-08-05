<?php

declare(strict_types=1);

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Tesseract\NativeCollector\NativeAgent;
use Tesseract\NativeCollector\Telemetry\EnvelopeFactory;
use Tesseract\NativeCollector\Telemetry\FrameworkEventGate;
use Tesseract\NativeCollector\Telemetry\TelemetryForwarder;

it('always drops collector self-noise in the framework event gate', function (): void {
    $gate = new FrameworkEventGate;
    $queueing = new JobQueueing(
        'redis',
        'emails',
        'App\\Jobs\\SendReceipt',
        json_encode(['uuid' => 'payload-job-42'], JSON_THROW_ON_ERROR),
        null,
    );

    expect($gate->accepts('Tesseract\NativeCollector\Telemetry\SomethingInternal', [], null))->toBeFalse()
        ->and($gate->accepts('bootstrapping: App\Providers\AppServiceProvider', [], null))->toBeFalse()
        ->and($gate->accepts('bootstrapped: App\Providers\AppServiceProvider', [], null))->toBeFalse()
        ->and($gate->accepts('creating: welcome', [], null))->toBeFalse()
        ->and($gate->accepts(JobQueueing::class, [$queueing], null))->toBeFalse()
        ->and($gate->accepts('composing: welcome', [new stdClass], null))->toBeTrue()
        ->and($gate->accepts('App\Events\OrderShipped', [new stdClass], null))->toBeTrue();
});

it('drops wildcard copies of events owned by dedicated listeners', function (): void {
    $gate = new FrameworkEventGate;
    $queryEvent = new QueryExecuted('select * from "users"', [], 1.0, DB::connection());
    $logEvent = new MessageLogged('info', 'User created', []);

    expect($gate->accepts(QueryExecuted::class, [$queryEvent], null))->toBeFalse()
        ->and($gate->accepts(MessageLogged::class, [$logEvent], null))->toBeFalse()
        ->and($gate->accepts('Illuminate\Http\Client\Events\ResponseReceived', [], null))->toBeFalse()
        ->and($gate->accepts('Illuminate\Http\Client\Events\ConnectionFailed', [], null))->toBeFalse()
        ->and($gate->accepts('custom.query.mirror', [$queryEvent], null))->toBeFalse()
        ->and($gate->accepts('App\Events\OrderShipped', [new stdClass], null))->toBeTrue();
});

it('drops query-shaped wildcard events for collector infrastructure tables', function (): void {
    $gate = new FrameworkEventGate;

    $pumpQueryEvent = new class
    {
        public string $sql = 'select * from "jobs" where "queue" = ? limit 1';
    };

    $appQueryEvent = new class
    {
        public string $sql = 'select * from "orders" where "status" = ?';
    };

    expect($gate->accepts('App\Events\QueryObserved', [$pumpQueryEvent], null))->toBeFalse()
        ->and($gate->accepts('App\Events\QueryObserved', [$appQueryEvent], null))->toBeTrue()
        ->and($gate->isCollectorInfrastructureQuery('delete from "cache_locks" where "key" = ?'))->toBeTrue()
        ->and($gate->isCollectorInfrastructureQuery('select * from "users"'))->toBeFalse();
});

it('drops queue events for the pump job and cache events for pump keys', function (): void {
    $gate = new FrameworkEventGate;
    $suppressedWriting = new WritingKey('file', 'tesseract:pump:timed', 'value', 60, []);
    $suppressedWritten = new KeyWritten('file', 'tesseract:pump:timed', 'value', 60, []);

    $pumpJob = new class
    {
        public function resolveName(): string
        {
            return 'Tesseract\NativeCollector\Jobs\PumpTesseractCommands';
        }
    };

    $queueEvent = new class
    {
        public ?object $job = null;
    };
    $queueEvent->job = $pumpJob;

    $pumpCacheEvent = new class
    {
        public string $key = 'tesseract:pump:alive';
    };

    $restartCacheEvent = new class
    {
        public string $key = 'illuminate:queue:restart';
    };

    $appCacheEvent = new class
    {
        public string $key = 'users:1:profile';
    };

    expect($gate->accepts(WritingKey::class, [$suppressedWriting], null))->toBeFalse()
        ->and($gate->accepts($suppressedWritten::class, [$suppressedWritten], null))->toBeFalse()
        ->and($gate->cacheOperationDurationMs($suppressedWritten))->toBeNull()
        ->and($gate->accepts('Illuminate\Queue\Events\JobProcessing', [$queueEvent], null))->toBeFalse()
        ->and($gate->accepts('Illuminate\Cache\Events\CacheHit', [$pumpCacheEvent], null))->toBeFalse()
        ->and($gate->accepts('Illuminate\Cache\Events\KeyWritten', [$restartCacheEvent], null))->toBeFalse()
        ->and($gate->accepts('Illuminate\Cache\Events\CacheHit', [$appCacheEvent], null))->toBeTrue();
});

it('reserves framework event capacity for priority events and caps each request', function (): void {
    $gate = new class extends FrameworkEventGate
    {
        public int $second = 0;

        protected function nowSeconds(): int
        {
            return $this->second;
        }
    };

    $acceptedForNavigation = 0;

    for ($i = 0; $i < 45; $i++) {
        if ($gate->accepts('Illuminate\Foundation\Events\DiagnosticProbe', [], 'nav-1-aaaaaaaa')) {
            $acceptedForNavigation++;
        }
    }

    expect($acceptedForNavigation)->toBe(40)
        ->and($gate->rejectionReason())->toBe('per-second-limit');

    for ($i = 0; $i < 10; $i++) {
        if ($gate->accepts('App\Events\PriorityProbe', [], 'nav-1-aaaaaaaa')) {
            $acceptedForNavigation++;
        }
    }

    expect($acceptedForNavigation)->toBe(50)
        ->and($gate->accepts('App\Events\PriorityProbe', [], 'nav-1-aaaaaaaa'))->toBeFalse()
        ->and($gate->rejectionReason())->toBe('per-second-limit');

    $gate->second = 1;

    for ($i = 0; $i < 40; $i++) {
        if ($gate->accepts('Illuminate\Foundation\Events\DiagnosticProbe', [], 'nav-1-aaaaaaaa')) {
            $acceptedForNavigation++;
        }
    }

    for ($i = 0; $i < 10; $i++) {
        if ($gate->accepts('App\Events\PriorityProbe', [], 'nav-1-aaaaaaaa')) {
            $acceptedForNavigation++;
        }
    }

    expect($acceptedForNavigation)->toBe(100)
        ->and($gate->accepts('App\Events\PriorityProbe', [], 'nav-1-aaaaaaaa'))->toBeFalse()
        ->and($gate->rejectionReason())->toBe('per-request-limit');

    $gate->second = 2;

    expect($gate->accepts('App\Events\PriorityProbe', [], 'nav-2-bbbbbbbb'))->toBeTrue();
});

it('forwards wildcard framework events through the agent and drops noise end to end', function (): void {
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

    $dispatcher->dispatch('custom.thing', ['hello']);

    $frameworkEnvelopes = array_values(array_filter(
        $agent->ingested,
        static fn (array $envelope): bool => $envelope['kind'] === 'server.event.dispatched',
    ));

    expect($frameworkEnvelopes)->toHaveCount(1)
        ->and($frameworkEnvelopes[0]['payload']['name'])->toBe('custom.thing')
        ->and($frameworkEnvelopes[0]['payload']['payloadPreview'])->toBeString();

    $agent->ingested = [];
    $dispatcher->dispatch('bootstrapping: App\Providers\AppServiceProvider');
    $dispatcher->dispatch('Tesseract\NativeCollector\Telemetry\SomethingInternal');

    expect($agent->ingested)->toBe([]);

    $agent->ingested = [];
    $dispatcher->dispatch(new QueryExecuted('select * from "users"', [], 1.0, DB::connection()));

    $kinds = array_column($agent->ingested, 'kind');

    expect($kinds)->toContain('server.query.executed')
        ->and($kinds)->not->toContain('server.event.dispatched');
});

it('reports capped framework telemetry instead of silently discarding it', function (): void {
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
    $gate = new class extends FrameworkEventGate
    {
        protected function nowSeconds(): int
        {
            return 1;
        }
    };
    $dispatcher = new Dispatcher(app());
    (new TelemetryForwarder($agent, new EnvelopeFactory, $gate))->subscribe($dispatcher);

    for ($i = 0; $i < 41; $i++) {
        $dispatcher->dispatch('Illuminate\Foundation\Events\DiagnosticProbe', [$i]);
    }

    $dispatcher->dispatch('App\Events\PriorityProbe');

    $dropped = collect($agent->ingested)->firstWhere('kind', 'collector.telemetry.dropped');

    expect($dropped)->not->toBeNull()
        ->and($dropped['payload']['total'])->toBe(1)
        ->and($dropped['payload']['sources']['framework-events']['reasons'])
        ->toBe(['per-second-limit' => 1])
        ->and($dropped['payload']['sources']['framework-events']['samples'])
        ->toBe(['Illuminate\Foundation\Events\DiagnosticProbe']);
});
