<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tesseract\NativeCollector\Telemetry\EnvelopeFactory;
use Tesseract\NativeCollector\Telemetry\FrameworkEventGate;

class NativeTelemetryBroadcastEvent implements ShouldBroadcast
{
    public string $connection = 'redis';

    public string $queue = 'broadcasts';

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.42')];
    }

    public function broadcastAs(): string
    {
        return 'orders.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => 42,
            'status' => 'paid',
        ];
    }

    public function broadcastWhen(): bool
    {
        return true;
    }
}

it('builds a framework event envelope the desktop can render', function (): void {
    $factory = new EnvelopeFactory;

    $envelope = $factory->frameworkEvent(
        CacheHit::class,
        [new stdClass],
        ['App\Listeners\AuditListener@handle'],
        'nav-1-abc12345',
        null,
        'trace-123',
        'parent-456',
        'action',
    );

    expect($envelope['kind'])->toBe('server.event.dispatched')
        ->and($envelope['stream'])->toBe('framework')
        ->and($envelope['correlation'])->toBe([
            'requestId' => 'nav-1-abc12345',
            'traceId' => 'trace-123',
            'parentRequestId' => 'parent-456',
        ])
        ->and($envelope['payload']['name'])->toBe(CacheHit::class)
        ->and($envelope['payload']['label'])->toBe('CacheHit')
        ->and($envelope['payload']['className'])->toBe(stdClass::class)
        ->and($envelope['payload']['broadcast'])->toBeFalse()
        ->and($envelope['payload']['payloadPreview'])->toBeString()
        ->and($envelope['payload']['listeners'])->toBe(['App\Listeners\AuditListener@handle'])
        ->and($envelope['payload']['category'])->toBe('cache')
        ->and($envelope['payload']['timestampMs'])->toBeFloat()
        ->and($envelope['payload']['stage'])->toBe('action')
        ->and($envelope['payload']['memoryUsageMb'])->toBeFloat()
        ->and($envelope['payload']['memoryPeakMb'])->toBeFloat();
});

it('builds structured dump envelopes', function (): void {
    $envelope = (new EnvelopeFactory)->dump(
        ['probe' => 'dump-value'],
        'Probe payload',
        'nav-1-abc12345',
    );

    expect($envelope['kind'])->toBe('server.dump.recorded')
        ->and($envelope['stream'])->toBe('dump')
        ->and($envelope['correlation'])->toBe(['requestId' => 'nav-1-abc12345'])
        ->and($envelope['payload'])->toMatchArray([
            'requestId' => 'nav-1-abc12345',
            'channel' => 'dump',
            'level' => 'debug',
            'message' => 'Probe payload',
        ])
        ->and($envelope['payload']['context'])->toContain('dump-value')
        ->and($envelope['payload']['dump'])->toContain('dump-value');
});

it('builds correlated native device event requests with sensitive bridge payloads redacted', function (): void {
    [$started, $finished] = (new EnvelopeFactory)->nativeEvent([
        'event' => 'Native\\Mobile\\Events\\Scanner\\CodeScanned',
        'class' => 'App\\NativeComponents\\Scanner',
        'method' => 'handleScan',
        'uri' => '/scanner',
        'payload' => [
            'data' => 'otpauth://totp/secret',
            'format' => 'QR_CODE',
            'id' => 'scan-1',
        ],
        'stateBefore' => ['scans' => 0],
        'stateAfter' => ['scans' => 1],
        'renderCount' => 2,
        'durationMs' => 3.25,
        'error' => null,
    ], 'evt-1-aabbccdd', 'trace-123', 'nav-1-parent');

    $requestPayload = json_decode($started['payload']['requestPayloadPreview'], true, flags: JSON_THROW_ON_ERROR);

    expect($started['kind'])->toBe('server.request.started')
        ->and($started['correlation'])->toBe([
            'requestId' => 'evt-1-aabbccdd',
            'traceId' => 'trace-123',
            'parentRequestId' => 'nav-1-parent',
        ])
        ->and($requestPayload)->toBe([
            'event' => 'Native\\Mobile\\Events\\Scanner\\CodeScanned',
            'handler' => 'handleScan',
            'payload' => [
                'data' => '[redacted]',
                'format' => 'QR_CODE',
                'id' => 'scan-1',
            ],
        ])
        ->and($finished['kind'])->toBe('server.request.finished')
        ->and($finished['correlation'])->toBe($started['correlation'])
        ->and($finished['payload']['nativeEvent'])->toMatchArray([
            'kind' => 'device',
            'event' => 'CODE_SCANNED',
            'component' => 'Scanner',
            'handler' => 'handleScan',
            'screen' => '/scanner',
            'renderCount' => 2,
            'durationMs' => 3.25,
            'changes' => [[
                'key' => 'scans',
                'from' => '0',
                'to' => '1',
            ]],
            'args' => [
                'data=[redacted]',
                'format=QR_CODE',
                'id=scan-1',
            ],
        ])
        ->and($finished['payload']['memoryUsageMb'])->toBeFloat()
        ->and($finished['payload']['memoryPeakMb'])->toBeFloat();
});

it('strips query credentials and fragments from native deep link event payloads', function (): void {
    [$started, $finished] = (new EnvelopeFactory)->nativeEvent([
        'event' => '__deeplink',
        'class' => 'App\\NativeComponents\\Profile',
        'method' => '__navigate',
        'uri' => '/profile',
        'payload' => [
            'uri' => '/profile?token=secret-value#billing',
        ],
        'stateBefore' => [],
        'stateAfter' => [],
        'renderCount' => 0,
        'durationMs' => 0.5,
        'error' => null,
    ], 'evt-2-aabbccdd', 'trace-456', 'nav-2-parent');

    $requestPayload = json_decode($started['payload']['requestPayloadPreview'], true, flags: JSON_THROW_ON_ERROR);
    $responsePayload = json_decode($finished['payload']['responsePayloadPreview'], true, flags: JSON_THROW_ON_ERROR);

    expect($requestPayload['payload'])->toBe(['uri' => '/profile'])
        ->and($responsePayload['payload'])->toBe(['uri' => '/profile'])
        ->and($finished['payload']['responseSummary'])->toContain('Uri=/profile')
        ->and($finished['payload']['responseSummary'])->not->toContain('secret-value')
        ->and($finished['payload']['nativeEvent']['args'])->toBe(['uri=/profile']);
});

it('bounds native event telemetry before serialization and marks truncation', function (): void {
    config([
        'tesseract-native.observability.native_events.max_entries' => 6,
        'tesseract-native.observability.native_events.max_bytes' => 128,
        'tesseract-native.observability.native_events.max_value_bytes' => 24,
        'tesseract-native.observability.native_events.max_depth' => 2,
    ]);

    [$started, $finished] = (new EnvelopeFactory)->nativeEvent([
        'event' => 'Native\\Mobile\\Events\\Scanner\\CodeScanned',
        'class' => 'App\\NativeComponents\\Scanner',
        'method' => 'handleScan',
        'uri' => '/scanner',
        'payload' => [
            'password' => 'top-secret',
            'message' => str_repeat('x', 500),
            'items' => range(1, 50),
            'ignored' => 'not-captured',
        ],
        'stateBefore' => ['count' => 0, 'password' => 'old-secret'],
        'stateAfter' => [
            'count' => 1,
            'password' => 'new-secret',
            ...array_fill_keys(array_map(static fn (int $index): string => 'state'.$index, range(1, 20)), 'value'),
        ],
        'durationMs' => 1.25,
    ], 'evt-bounded', 'trace-bounded', 'nav-parent');

    $requestPayload = json_decode($started['payload']['requestPayloadPreview'], true, flags: JSON_THROW_ON_ERROR);
    $responsePayload = json_decode($finished['payload']['responsePayloadPreview'], true, flags: JSON_THROW_ON_ERROR);
    $passwordState = collect($finished['payload']['nativeEvent']['stateAfter'])->firstWhere('key', 'password');

    expect($requestPayload['truncated'])->toBeTrue()
        ->and($requestPayload['payload']['password'])->toBe('[redacted]')
        ->and($requestPayload['payload']['message'])->toHaveLength(24)
        ->and($requestPayload['payload']['message'])->toEndWith('...')
        ->and($started['payload']['requestPayloadPreview'])->not->toContain('top-secret')
        ->and($finished['payload']['responseHeaders']['X-Native-Truncated'])->toBe('true')
        ->and($finished['payload']['nativeEvent']['truncated'])->toBeTrue()
        ->and($finished['payload']['nativeEvent']['stateAfter'])->toHaveCount(6)
        ->and($passwordState['value'])->toBe('[redacted]')
        ->and($responsePayload['truncated'])->toBeTrue()
        ->and($finished['payload']['responsePayloadPreview'])->not->toContain('new-secret');
});

it('categorizes string events and truncates the payload preview', function (): void {
    $factory = new EnvelopeFactory;

    $envelope = $factory->frameworkEvent('eloquent.saved: App\Models\User', [str_repeat('a', 1000)]);

    expect($envelope)->not->toHaveKey('correlation')
        ->and($envelope['payload']['category'])->toBe('eloquent')
        ->and($envelope['payload']['label'])->toBe('eloquent.saved: App\Models\User')
        ->and($envelope['payload']['payloadPreview'])->toBeString()
        ->and(strlen($envelope['payload']['payloadPreview']))->toBeLessThanOrEqual(243);
});

it('adds structured context to native framework event envelopes', function (): void {
    $factory = new EnvelopeFactory;

    $cache = $factory->frameworkEvent(CacheHit::class, [
        new CacheHit('redis', 'orders:42', ['id' => 42], ['orders']),
    ]);
    $job = $factory->frameworkEvent(JobQueued::class, [
        new JobQueued(
            'redis',
            'emails',
            'job-42',
            'App\Jobs\SendReceipt',
            json_encode([
                'displayName' => 'App\Jobs\SendReceipt',
                'data' => ['order_id' => 42],
            ], JSON_THROW_ON_ERROR),
            null,
        ),
    ]);
    $model = $factory->frameworkEvent('eloquent.saved: App\Models\Order', [
        new class
        {
            public function getKey(): int
            {
                return 42;
            }

            /** @return array<string, string> */
            public function getChanges(): array
            {
                return ['status' => 'paid'];
            }
        },
    ]);
    $broadcast = $factory->frameworkEvent(NativeTelemetryBroadcastEvent::class, [
        new NativeTelemetryBroadcastEvent,
    ]);

    expect($cache['payload']['eventContext'])->toMatchArray([
        'type' => 'cache',
        'operation' => 'hit',
        'key' => 'orders:42',
        'store' => 'redis',
        'tags' => ['orders'],
    ])
        ->and($job['payload']['eventContext'])->toMatchArray([
            'type' => 'job',
            'status' => 'queued',
            'name' => 'App\Jobs\SendReceipt',
            'label' => 'SendReceipt',
            'connection' => 'redis',
            'queue' => 'emails',
            'jobId' => 'job-42',
        ])
        ->and($job['payload']['eventContext']['payloadPreview'])->toContain('order_id')
        ->and($model['payload']['eventContext'])->toMatchArray([
            'type' => 'model',
            'event' => 'saved',
            'model' => 'App\Models\Order',
            'modelLabel' => 'Order',
            'modelId' => '42',
            'changes' => ['status' => 'paid'],
        ])
        ->and($broadcast['payload']['broadcast'])->toBeTrue()
        ->and($broadcast['payload']['category'])->toBe('broadcast')
        ->and($broadcast['payload']['eventContext'])->toMatchArray([
            'type' => 'broadcast',
            'event' => 'orders.updated',
            'channels' => ['private-orders.42'],
            'connection' => 'redis',
            'queue' => 'broadcasts',
            'shouldBroadcast' => true,
        ])
        ->and($broadcast['payload']['eventContext']['payloadPreview'])->toContain('order_id');
});

it('classifies cache write failures as cache operations', function (): void {
    $gate = new FrameworkEventGate;
    $writing = new WritingKey(
        'redis',
        'orders:43',
        ['status' => 'paid'],
        300,
        ['orders'],
    );
    $event = new KeyWriteFailed('redis', 'orders:43', ['status' => 'paid'], 300, ['orders']);

    expect($gate->accepts(WritingKey::class, [$writing], null))->toBeFalse()
        ->and($gate->accepts(KeyWriteFailed::class, [$event], null))->toBeTrue();

    $context = (new EnvelopeFactory)
        ->frameworkEvent(
            KeyWriteFailed::class,
            [$event],
            cacheDurationMs: $gate->cacheOperationDurationMs($event),
        )['payload']['eventContext'];

    expect($context)->toMatchArray([
        'type' => 'cache',
        'operation' => 'put-failed',
        'key' => 'orders:43',
        'store' => 'redis',
        'seconds' => 300,
    ])->and($context['durationMs'])->toBeFloat()->toBeGreaterThanOrEqual(0);
});

it('adds structured context for extended native framework events', function (): void {
    config()->set('tesseract-native.observability.mail.capture', 'preview');
    $factory = new EnvelopeFactory;
    $email = (new Email)
        ->from(new Address('sender@example.com', 'Anvil'))
        ->to('recipient@example.com')
        ->subject('Native collector coverage')
        ->html('<p>Captured by the native collector.</p>')
        ->text('Captured by the native collector.');
    $email->getHeaders()->addTextHeader('X-Anvil-Test', 'native collector');
    $sentMessage = new SentMessage(new SymfonySentMessage(
        $email,
        new SymfonyEnvelope(new Address('sender@example.com'), [new Address('recipient@example.com')]),
    ));
    $events = [
        'Illuminate\Mail\Events\MessageSending' => (object) ['message' => $email],
        MessageSent::class => new MessageSent($sentMessage),
        'Illuminate\Notifications\Events\NotificationSending' => (object) [
            'notification' => new stdClass,
            'notifiable' => (object) ['id' => 42],
            'channel' => 'mail',
        ],
        'Illuminate\Notifications\Events\NotificationSent' => (object) [
            'notification' => new stdClass,
            'notifiable' => (object) ['id' => 42],
            'channel' => 'mail',
            'response' => ['message_id' => 'notification-42'],
        ],
        'Illuminate\Bus\Events\BatchDispatched' => (object) [
            'batch' => (object) [
                'id' => 'batch-42',
                'name' => 'Rebuild index',
                'totalJobs' => 12,
            ],
        ],
        'Illuminate\Console\Events\CommandFinished' => (object) [
            'command' => 'reports:refresh',
            'exitCode' => 0,
        ],
        'Illuminate\Console\Events\ScheduledTaskFinished' => (object) [
            'task' => (object) [
                'command' => 'reports:refresh',
                'expression' => '*/5 * * * *',
                'exitCode' => 0,
            ],
            'runtime' => 0.125,
        ],
        'Illuminate\Auth\Access\Events\GateEvaluated' => (object) [
            'ability' => 'update-report',
            'result' => true,
            'arguments' => ['report-42'],
        ],
        'Illuminate\Redis\Events\CommandExecuted' => (object) [
            'command' => 'get',
            'parameters' => ['report:42'],
            'time' => 1.25,
            'connectionName' => 'cache',
        ],
        'composing: reports.summary' => new class
        {
            public function getName(): string
            {
                return 'reports.summary';
            }

            public function getPath(): string
            {
                return 'resources/views/reports/summary.blade.php';
            }

            public function getData(): array
            {
                return ['report' => new stdClass];
            }
        },
    ];

    $eventContexts = collect($events)
        ->map(fn (object $event, string $eventName): mixed => $factory
            ->frameworkEvent($eventName, [$event])['payload']['eventContext']);
    $mailStatuses = $eventContexts
        ->where('type', 'mail')
        ->pluck('status')
        ->values()
        ->all();
    $mailMessageIds = $eventContexts
        ->where('type', 'mail')
        ->pluck('messageId')
        ->unique()
        ->values()
        ->all();
    $notificationStatuses = $eventContexts
        ->where('type', 'notification')
        ->pluck('status')
        ->values()
        ->all();
    $contexts = $eventContexts->keyBy('type');

    expect($contexts)->toHaveKeys([
        'mail',
        'notification',
        'batch',
        'command',
        'schedule',
        'gate',
        'redis',
        'view',
    ])
        ->and($mailStatuses)->toBe(['sending', 'sent'])
        ->and($mailMessageIds)->toHaveCount(1)
        ->and($notificationStatuses)->toBe(['sending', 'sent'])
        ->and($contexts['mail']['subject'])->toBe('Native collector coverage')
        ->and($contexts['mail']['htmlPreview'])->toContain('<p>Captured by the native collector.</p>')
        ->and($contexts['mail']['textPreview'])->toBe('Captured by the native collector.')
        ->and($contexts['mail']['headers'])->toContain([
            'name' => 'X-Anvil-Test',
            'value' => 'native collector',
        ])
        ->and($contexts['notification']['notifiableId'])->toBe('42')
        ->and($contexts['batch']['totalJobs'])->toBe(12)
        ->and($contexts['schedule']['runtimeMs'])->toBe(125.0)
        ->and($contexts['redis']['command'])->toBe('GET')
        ->and($contexts['view']['name'])->toBe('reports.summary');
});

it('captures the text variant of multipart mail by default and requires full mode for source and attachment bodies', function (): void {
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Capture mode coverage')
        ->html('<p>Sensitive preview body.</p>')
        ->text('Sensitive preview body.')
        ->attach('attachment contents', 'proof.txt', 'text/plain');
    $event = (object) ['message' => $email];
    $factory = new EnvelopeFactory;

    config()->set('tesseract-native.observability.mail.capture', 'preview');
    $preview = $factory->frameworkEvent(
        'Illuminate\\Mail\\Events\\MessageSending',
        [$event],
    )['payload']['eventContext'];

    expect($preview['textPreview'])->toBe('Sensitive preview body.')
        ->and($preview['bodyPreview'])->toBe('Sensitive preview body.')
        ->and($preview['htmlPreview'])->toContain('<p>Sensitive preview body.</p>')
        ->and($preview['rawMime'])->toBeNull();

    config()->set('tesseract-native.observability.mail.capture', 'metadata');
    $metadata = $factory->frameworkEvent(
        'Illuminate\\Mail\\Events\\MessageSending',
        [$event],
    )['payload']['eventContext'];

    expect($metadata)->toMatchArray([
        'type' => 'mail',
        'subject' => 'Capture mode coverage',
    ])
        ->and($metadata['htmlPreview'])->toBeNull()
        ->and($metadata['bodyPreview'])->toBe('')
        ->and($metadata['rawMime'])->toBeNull()
        ->and($metadata['attachments'][0])->toMatchArray([
            'filename' => 'proof.txt',
            'mediaType' => 'text',
            'mediaSubtype' => 'plain',
        ])
        ->and($metadata['attachments'][0])->not->toHaveKey('contentBase64');

    config()->set('tesseract-native.observability.mail.capture', 'full');
    $full = $factory->frameworkEvent(
        'Illuminate\\Mail\\Events\\MessageSending',
        [$event],
    )['payload']['eventContext'];

    expect($full['htmlPreview'])->toContain('Sensitive preview body.')
        ->and($full['rawMime'])->toContain('Capture mode coverage')
        ->and(base64_decode($full['attachments'][0]['contentBase64'], true))
        ->toBe('attachment contents');

    config()->set('tesseract-native.observability.mail.capture', 'off');

    expect((new FrameworkEventGate)->accepts(
        'Illuminate\\Mail\\Events\\MessageSending',
        [$event],
        'request-mail',
    ))->toBeFalse();

    config()->set('tesseract-native.observability.mail.capture', 'preview');
});

it('builds a query envelope with durationMs, driver, familyHash, and slow flag', function (): void {
    $factory = new EnvelopeFactory;
    $event = new QueryExecuted('select * from "users"', [1], 12.34, DB::connection());

    $envelope = $factory->query($event, 'nav-1-abc12345');

    expect($envelope['correlation'])->toBe(['requestId' => 'nav-1-abc12345'])
        ->and($envelope['payload']['durationMs'])->toBe(12.34)
        ->and($envelope['payload']['timeMs'])->toBe(12.34)
        ->and($envelope['payload']['driver'])->toBe('sqlite')
        ->and($envelope['payload']['familyHash'])->toBe(md5('select * from "users"'))
        ->and($envelope['payload']['slow'])->toBeFalse()
        ->and($envelope['payload']['startedAtMs'])->toBeFloat()
        ->and($envelope['payload']['endedAtMs'])->toBeGreaterThanOrEqual($envelope['payload']['startedAtMs']);

    $slow = $factory->query(new QueryExecuted('select 1', [], 150.0, DB::connection()));

    expect($slow['payload']['slow'])->toBeTrue()
        ->and($slow)->not->toHaveKey('correlation');
});

it('correlates completed client requests to the current native request id', function (): void {
    $factory = new EnvelopeFactory;

    $event = new ResponseReceived(
        new ClientRequest(new PsrRequest('GET', 'https://api.example.com/users', ['Accept' => 'application/json'])),
        new ClientResponse(new PsrResponse(200, ['Content-Type' => 'application/json'], '{"ok":true}')),
    );

    $envelope = $factory->clientResponse($event, 'int-3-9f8e7d6c');

    expect($envelope['kind'])->toBe('server.client-request.completed')
        ->and($envelope['correlation'])->toBe(['requestId' => 'int-3-9f8e7d6c'])
        ->and($envelope['payload']['requestId'])->toBe('int-3-9f8e7d6c')
        ->and($envelope['payload']['durationMs'])->toBeFloat()
        ->and($envelope['payload']['responsePayloadPreview'])->toBe(['format' => 'json', 'value' => '{"ok":true}']);
});

it('detects json client responses by body when the content type is missing', function (): void {
    $factory = new EnvelopeFactory;

    $jsonBody = new ResponseReceived(
        new ClientRequest(new PsrRequest('GET', 'https://api.example.com/ping')),
        new ClientResponse(new PsrResponse(200, [], '{"pong":1}')),
    );

    $textBody = new ResponseReceived(
        new ClientRequest(new PsrRequest('GET', 'https://api.example.com/ping')),
        new ClientResponse(new PsrResponse(200, [], 'pong')),
    );

    expect($factory->clientResponse($jsonBody)['payload']['responsePayloadPreview']['format'])->toBe('json')
        ->and($factory->clientResponse($textBody)['payload']['responsePayloadPreview']['format'])->toBe('text')
        ->and($factory->clientResponse($textBody))->not->toHaveKey('correlation');
});

it('emits log context as a JSON string with channel and timestampMs', function (): void {
    $factory = new EnvelopeFactory;
    $event = new MessageLogged('info', 'User created', ['id' => 7]);

    $envelope = $factory->log($event, 'nav-2-deadbeef');

    expect($envelope['correlation'])->toBe(['requestId' => 'nav-2-deadbeef'])
        ->and($envelope['payload']['context'])->toBe('{"id":7}')
        ->and($envelope['payload']['channel'])->toBeString()
        ->and($envelope['payload']['timestampMs'])->toBeFloat();
});
