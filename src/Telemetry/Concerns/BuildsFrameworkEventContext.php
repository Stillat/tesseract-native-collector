<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry\Concerns;

use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

trait BuildsFrameworkEventContext
{
    use ReadsFrameworkEventValues;

    protected function frameworkEventContext(string $eventName, array $payload, ?float $cacheDurationMs = null): ?array
    {
        $first = $payload[0] ?? null;

        $existingContext = $this->broadcastEventContext($eventName, $first)
            ?? $this->cacheEventContext($eventName, $first, $cacheDurationMs)
            ?? $this->queueEventContext($eventName, $first)
            ?? $this->modelEventContext($eventName, $first);

        if ($existingContext !== null || ! is_object($first)) {
            return $existingContext;
        }

        return match (true) {
            Str::startsWith($eventName, [
                'Illuminate\\Mail\\Events\\MessageSending',
                'Illuminate\\Mail\\Events\\MessageSent',
            ]) => $this->mailEventContext($first, $this->deliveryEventStatus($eventName)),
            Str::startsWith($eventName, [
                'Illuminate\\Notifications\\Events\\NotificationSending',
                'Illuminate\\Notifications\\Events\\NotificationSent',
            ]) => $this->notificationEventContext($first, $this->deliveryEventStatus($eventName)),
            $eventName === 'Illuminate\\Bus\\Events\\BatchDispatched' => $this->batchEventContext($first),
            $eventName === 'Illuminate\\Console\\Events\\CommandFinished' => $this->commandEventContext($first),
            Str::startsWith($eventName, [
                'Illuminate\\Console\\Events\\ScheduledTask',
                'Illuminate\\Console\\Events\\ScheduledBackgroundTask',
            ]) => $this->scheduleEventContext($eventName, $first),
            $eventName === 'Illuminate\\Auth\\Access\\Events\\GateEvaluated' => $this->gateEventContext($first),
            $eventName === 'Illuminate\\Redis\\Events\\CommandExecuted' => $this->redisEventContext($first),
            str_starts_with($eventName, 'composing:') => $this->viewEventContext($eventName, $first),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function broadcastEventContext(string $eventName, mixed $event): ?array
    {
        if (! $this->isBroadcastEvent($eventName, $event)) {
            return null;
        }

        $alias = $this->safeMethodValue($event, 'broadcastAs');
        $channels = $this->broadcastChannels($this->safeMethodValue($event, 'broadcastOn'));
        $payload = $this->broadcastPayload($event);
        $queue = $this->safeMethodValue($event, 'broadcastQueue')
            ?? $this->publicProperty($event, 'broadcastQueue')
            ?? $this->publicProperty($event, 'queue');
        $connection = $this->publicProperty($event, 'connection');
        $connections = $this->broadcastConnections($this->safeMethodValue($event, 'broadcastConnections'));
        $shouldBroadcast = $this->safeMethodValue($event, 'broadcastWhen');

        return [
            'type' => 'broadcast',
            'sourceFrame' => $this->frameworkClassSourceFrame($event::class),
            'event' => $this->stringOrDefault(
                $alias,
                class_exists($eventName) ? class_basename($eventName) : $eventName,
            ),
            'channels' => $channels,
            'queue' => is_scalar($queue) && (string) $queue !== '' ? (string) $queue : null,
            'connection' => is_scalar($connection) && (string) $connection !== '' ? (string) $connection : null,
            'connections' => $connections,
            'shouldBroadcast' => is_bool($shouldBroadcast) ? $shouldBroadcast : null,
            'payloadPreview' => $payload !== null ? $this->stringify($payload) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function cacheEventContext(string $eventName, mixed $event, ?float $durationMs = null): ?array
    {
        if (! str_starts_with($eventName, 'Illuminate\\Cache\\Events\\')) {
            return null;
        }

        $operation = match (class_basename($eventName)) {
            'CacheHit' => 'hit',
            'CacheMissed' => 'miss',
            'KeyWritten' => 'put',
            'KeyWriteFailed' => 'put-failed',
            'KeyForgotten' => 'forget',
            'KeyForgetFailed' => 'forget-failed',
            'CacheFlushed' => 'flush',
            'CacheFlushFailed' => 'flush-failed',
            default => null,
        };

        if ($operation === null) {
            return null;
        }

        $key = $this->publicProperty($event, 'key');
        $store = $this->publicProperty($event, 'storeName');
        $seconds = $this->publicProperty($event, 'seconds');
        $tags = $this->publicProperty($event, 'tags');

        return [
            'type' => 'cache',
            'operation' => $operation,
            'durationMs' => $durationMs,
            'key' => is_scalar($key) && (string) $key !== '' ? (string) $key : '*',
            'store' => is_scalar($store) && (string) $store !== '' ? (string) $store : 'default',
            'tags' => is_array($tags) ? array_values(array_filter($tags, 'is_scalar')) : [],
            'seconds' => is_numeric($seconds) ? (int) $seconds : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function queueEventContext(string $eventName, mixed $event): ?array
    {
        if (! str_starts_with($eventName, 'Illuminate\\Queue\\Events\\')) {
            return null;
        }

        $status = match (class_basename($eventName)) {
            'JobQueued', 'JobQueueing' => 'queued',
            'JobPopped', 'JobProcessing' => 'running',
            'JobAttempted', 'JobProcessed' => 'processed',
            'JobReleasedAfterException' => 'released',
            'JobExceptionOccurred', 'JobFailed', 'JobTimedOut' => 'failed',
            default => null,
        };

        if ($status === null) {
            return null;
        }

        $job = $this->publicProperty($event, 'job');
        $queue = $this->publicProperty($event, 'queue')
            ?? $this->safeMethodValue($job, 'getQueue');
        $payload = $this->queuePayload($event, $job);
        $name = $this->queueJobName($event, $job, $payload);
        $jobId = $this->publicProperty($event, 'id')
            ?? $this->safeMethodValue($job, 'getJobId')
            ?? (is_array($payload) ? ($payload['uuid'] ?? null) : null);
        $attempts = $this->safeMethodValue($job, 'attempts');
        $exception = $this->publicProperty($event, 'exception');

        return [
            'type' => 'job',
            'status' => $status,
            'name' => $name,
            'sourceFrame' => $this->frameworkClassSourceFrame($name),
            'label' => $name !== '' ? class_basename($name) : 'Queued job',
            'connection' => $this->stringOrDefault(
                $this->publicProperty($event, 'connectionName')
                    ?? $this->publicProperty($event, 'connection'),
                'default',
            ),
            'queue' => $this->stringOrDefault($queue, 'default'),
            'jobId' => is_scalar($jobId) && (string) $jobId !== '' ? (string) $jobId : null,
            'attempts' => is_numeric($attempts) ? (int) $attempts : null,
            'payloadPreview' => $payload !== null ? $this->stringify($payload) : null,
            'exception' => $exception instanceof Throwable
                ? [
                    'type' => $exception::class,
                    'message' => $exception->getMessage(),
                ]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function modelEventContext(string $eventName, mixed $model): ?array
    {
        if (! preg_match('/^eloquent\.(?<event>[A-Za-z]+):\s*(?<model>.+)$/', $eventName, $matches)) {
            return null;
        }

        $event = match ($matches['event']) {
            'retrieved' => 'retrieved',
            'created' => 'created',
            'updated' => 'updated',
            'saved' => 'saved',
            'deleted' => 'deleted',
            'restored' => 'restored',
            'forceDeleted' => 'force-deleted',
            default => null,
        };

        if ($event === null) {
            return null;
        }

        $modelClass = trim((string) ($matches['model'] ?? ''));
        $modelId = $this->safeMethodValue($model, 'getKey')
            ?? $this->publicProperty($model, 'id');
        $changes = $this->safeMethodValue($model, 'getChanges');

        return [
            'type' => 'model',
            'event' => $event,
            'model' => $modelClass !== '' ? $modelClass : (is_object($model) ? $model::class : 'Model'),
            'sourceFrame' => $this->frameworkClassSourceFrame(
                $modelClass !== '' ? $modelClass : (is_object($model) ? $model::class : null),
            ),
            'modelLabel' => $modelClass !== '' ? class_basename($modelClass) : 'Model',
            'modelId' => is_scalar($modelId) && (string) $modelId !== '' ? (string) $modelId : null,
            'changes' => is_array($changes) && $changes !== [] ? $this->safeScope($changes) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function mailEventContext(object $event, string $status): ?array
    {
        $capture = strtolower((string) config('tesseract-native.observability.mail.capture', 'preview'));

        if ($capture === 'off') {
            return null;
        }

        $message = $this->safePropertyValue($event, 'message');

        if (! is_object($message)) {
            return null;
        }

        $originalMessage = $this->safeMethodValue($message, 'getOriginalMessage');

        if (is_object($originalMessage)) {
            $message = $originalMessage;
        }

        $capturesPreview = in_array($capture, ['preview', 'full'], true);
        $capturesFull = $capture === 'full';
        $previewMaxBytes = max((int) config(
            'tesseract-native.observability.mail.preview_max_bytes',
            self::MaxMailPreviewLength,
        ), 0);
        $rawMaxBytes = max((int) config('tesseract-native.observability.mail.raw_max_bytes', 131072), 0);
        $html = $capturesPreview ? $this->safeMethodValue($message, 'getHtmlBody') : null;
        $text = $capturesPreview ? $this->safeMethodValue($message, 'getTextBody') : null;
        $data = $this->publicProperty($event, 'data');
        $mailable = is_array($data) ? ($data['__laravel_mailable'] ?? null) : null;
        $attachments = $this->safeMethodValue($message, 'getAttachments');
        $headers = $this->safeMethodValue($message, 'getHeaders');
        $rawMime = $capturesFull ? $this->safeMethodValue($message, 'toString') : null;

        return [
            'type' => 'mail',
            'messageId' => (string) spl_object_id($message),
            'status' => $status,
            'subject' => $this->stringOrDefault($this->safeMethodValue($message, 'getSubject'), '(no subject)'),
            'from' => $this->mailAddressLabels($this->safeMethodValue($message, 'getFrom')),
            'to' => $this->mailAddressLabels($this->safeMethodValue($message, 'getTo')),
            'cc' => $this->mailAddressLabels($this->safeMethodValue($message, 'getCc')),
            'bcc' => $this->mailAddressLabels($this->safeMethodValue($message, 'getBcc')),
            'replyTo' => $this->mailAddressLabels($this->safeMethodValue($message, 'getReplyTo')),
            'mailable' => is_object($mailable) ? $mailable::class : null,
            'sourceFrame' => is_object($mailable)
                ? $this->frameworkClassSourceFrame($mailable::class)
                : null,
            'queued' => is_object($mailable) && is_a($mailable, 'Illuminate\\Contracts\\Queue\\ShouldQueue'),
            'attachments' => $this->mailAttachmentMetadata($attachments, $capturesFull),
            'headers' => $this->mailHeaders($headers, $previewMaxBytes),
            'htmlPreview' => is_string($html) && $html !== ''
                ? Str::limit($html, $previewMaxBytes, '...')
                : null,
            'textPreview' => is_string($text) && $text !== ''
                ? Str::limit($text, $previewMaxBytes, '...')
                : null,
            'bodyPreview' => Str::limit($this->normalizedSummaryText(
                is_string($text) && $text !== '' ? $text : (is_string($html) ? $html : ''),
            ), $previewMaxBytes, '...'),
            'rawMime' => is_string($rawMime) && $rawMime !== ''
                ? Str::limit($rawMime, $rawMaxBytes, '...')
                : null,
        ];
    }

    /** @return list<array{name: string, value: string}> */
    protected function mailHeaders(mixed $headers, int $maxBytes): array
    {
        $items = $this->safeMethodValue($headers, 'all');

        if (! is_iterable($items)) {
            return [];
        }

        $result = [];

        foreach ($items as $header) {
            if (! is_object($header)) {
                continue;
            }

            $name = $this->safeMethodValue($header, 'getName');
            $value = $this->safeMethodValue($header, 'getBodyAsString');

            if (! is_string($name) || $name === '' || ! is_string($value)) {
                continue;
            }

            $result[] = [
                'name' => $name,
                'value' => Str::limit($value, $maxBytes, '...'),
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    protected function mailAttachmentMetadata(mixed $attachments, bool $includeContent): array
    {
        if (! is_iterable($attachments)) {
            return [];
        }

        $maxAttachments = max((int) config('tesseract-native.observability.mail.max_attachments', 10), 0);
        $maxBytes = max((int) config('tesseract-native.observability.mail.attachment_max_bytes', 65536), 0);
        $metadata = [];

        foreach ($attachments as $attachment) {
            if (count($metadata) >= $maxAttachments || ! is_object($attachment)) {
                break;
            }

            $body = $includeContent ? $this->mailAttachmentBody($attachment) : null;
            $content = is_string($body) ? substr($body, 0, $maxBytes) : null;
            $metadata[] = array_filter([
                'filename' => $this->safeMethodValue($attachment, 'getFilename'),
                'mediaType' => $this->safeMethodValue($attachment, 'getMediaType'),
                'mediaSubtype' => $this->safeMethodValue($attachment, 'getMediaSubtype'),
                'contentId' => $this->safeMethodValue($attachment, 'getContentId'),
                'size' => is_string($body) ? strlen($body) : null,
                'contentBase64' => $content !== null ? base64_encode($content) : null,
                'contentTruncated' => is_string($body) && strlen($body) > $maxBytes ? true : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return $metadata;
    }

    protected function mailAttachmentBody(object $attachment): ?string
    {
        $body = $this->safeMethodValue($attachment, 'getBody');

        if (is_string($body)) {
            return $body;
        }

        if (is_resource($body)) {
            $position = ftell($body);
            $contents = stream_get_contents($body);

            if (is_int($position)) {
                fseek($body, $position);
            }

            return is_string($contents) ? $contents : null;
        }

        $encoded = $this->safeMethodValue($attachment, 'bodyToString');

        if (! is_string($encoded)) {
            return null;
        }

        if ($this->safeMethodValue($attachment, 'getEncoding') === 'base64') {
            $decoded = base64_decode($encoded, true);

            return $decoded === false ? $encoded : $decoded;
        }

        return $encoded;
    }

    /** @return array<string, mixed> */
    protected function notificationEventContext(object $event, string $status): array
    {
        $notification = $this->publicProperty($event, 'notification');
        $notifiable = $this->publicProperty($event, 'notifiable');
        $notifiableId = $this->safeMethodValue($notifiable, 'getKey')
            ?? $this->publicProperty($notifiable, 'id');

        return [
            'type' => 'notification',
            'notificationId' => is_object($notification) ? (string) spl_object_id($notification) : null,
            'status' => $status,
            'notification' => is_object($notification) ? $notification::class : get_debug_type($notification),
            'sourceFrame' => is_object($notification)
                ? $this->frameworkClassSourceFrame($notification::class)
                : null,
            'label' => is_object($notification) ? class_basename($notification) : 'Notification',
            'channel' => $this->stringOrDefault($this->publicProperty($event, 'channel'), 'unknown'),
            'notifiable' => is_object($notifiable) ? $notifiable::class : get_debug_type($notifiable),
            'notifiableId' => is_scalar($notifiableId) ? (string) $notifiableId : null,
            'responsePreview' => $this->stringify($this->publicProperty($event, 'response')),
        ];
    }

    protected function deliveryEventStatus(string $eventName): string
    {
        return Str::endsWith($eventName, 'Sending') ? 'sending' : 'sent';
    }

    /** @return array<string, mixed>|null */
    protected function batchEventContext(object $event): ?array
    {
        $batch = $this->publicProperty($event, 'batch');

        if (! is_object($batch)) {
            return null;
        }

        return [
            'type' => 'batch',
            'id' => $this->stringOrDefault($this->publicProperty($batch, 'id'), 'unknown'),
            'name' => $this->stringOrDefault($this->publicProperty($batch, 'name'), 'Unnamed batch'),
            'connection' => $this->stringOrDefault($this->publicProperty($batch, 'connection'), 'default'),
            'queue' => $this->stringOrDefault($this->publicProperty($batch, 'queue'), 'default'),
            'totalJobs' => (int) ($this->publicProperty($batch, 'totalJobs') ?? 0),
            'pendingJobs' => (int) ($this->publicProperty($batch, 'pendingJobs') ?? 0),
            'failedJobs' => (int) ($this->publicProperty($batch, 'failedJobs') ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    protected function commandEventContext(object $event): array
    {
        $input = $this->publicProperty($event, 'input');
        $arguments = $this->safeMethodValue($input, 'getArguments');
        $options = $this->safeMethodValue($input, 'getOptions');

        return [
            'type' => 'command',
            'command' => $this->stringOrDefault($this->publicProperty($event, 'command'), 'unknown'),
            'exitCode' => (int) ($this->publicProperty($event, 'exitCode') ?? 0),
            'arguments' => is_array($arguments) ? $this->safeScope($arguments) : null,
            'options' => is_array($options) ? $this->safeScope($options) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    protected function scheduleEventContext(string $eventName, object $event): ?array
    {
        $task = $this->publicProperty($event, 'task');

        if (! is_object($task)) {
            return null;
        }

        $status = match (class_basename($eventName)) {
            'ScheduledTaskStarting' => 'running',
            'ScheduledTaskFinished', 'ScheduledBackgroundTaskFinished' => 'processed',
            'ScheduledTaskFailed' => 'failed',
            'ScheduledTaskSkipped' => 'skipped',
            default => null,
        };

        if ($status === null) {
            return null;
        }

        $exception = $this->publicProperty($event, 'exception');

        $taskName = $this->stringOrDefault(
            $this->safeMethodValue($task, 'getSummaryForDisplay') ?? $this->publicProperty($task, 'command'),
            'Scheduled task',
        );
        $expression = $this->publicProperty($task, 'expression');
        $timezone = $this->publicProperty($task, 'timezone');

        return [
            'type' => 'schedule',
            'taskId' => hash('xxh128', json_encode([
                $taskName,
                $expression,
                $timezone instanceof \DateTimeZone ? $timezone->getName() : $timezone,
            ], JSON_THROW_ON_ERROR)),
            'status' => $status,
            'task' => $taskName,
            'description' => $this->publicProperty($task, 'description'),
            'expression' => $expression,
            'timezone' => $timezone instanceof \DateTimeZone ? $timezone->getName() : $timezone,
            'runtimeMs' => is_numeric($this->publicProperty($event, 'runtime'))
                ? round((float) $this->publicProperty($event, 'runtime') * 1000, 2)
                : null,
            'exitCode' => is_numeric($this->publicProperty($task, 'exitCode'))
                ? (int) $this->publicProperty($task, 'exitCode')
                : null,
            'exception' => $exception instanceof Throwable
                ? ['type' => $exception::class, 'message' => $exception->getMessage()]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function gateEventContext(object $event): array
    {
        $user = $this->publicProperty($event, 'user');
        $userId = $this->safeMethodValue($user, 'getAuthIdentifier')
            ?? $this->safeMethodValue($user, 'getKey');

        return [
            'type' => 'gate',
            'ability' => $this->stringOrDefault($this->publicProperty($event, 'ability'), 'unknown'),
            'result' => $this->publicProperty($event, 'result'),
            'user' => is_object($user) ? $user::class : null,
            'userId' => is_scalar($userId) ? (string) $userId : null,
            'argumentsPreview' => $this->stringify($this->publicProperty($event, 'arguments')),
        ];
    }

    /** @return array<string, mixed> */
    protected function redisEventContext(object $event): array
    {
        return [
            'type' => 'redis',
            'command' => strtoupper($this->stringOrDefault($this->publicProperty($event, 'command'), 'UNKNOWN')),
            'connection' => $this->stringOrDefault($this->publicProperty($event, 'connectionName'), 'default'),
            'durationMs' => is_numeric($this->publicProperty($event, 'time'))
                ? round((float) $this->publicProperty($event, 'time'), 2)
                : null,
            'parametersPreview' => $this->stringify($this->publicProperty($event, 'parameters')),
        ];
    }

    /** @return array<string, mixed> */
    protected function viewEventContext(string $eventName, object $view): array
    {
        $data = $this->safeMethodValue($view, 'getData');

        return [
            'type' => 'view',
            'name' => $this->stringOrDefault($this->safeMethodValue($view, 'getName'), Str::after($eventName, 'composing:')),
            'path' => $this->safeMethodValue($view, 'getPath'),
            'dataKeys' => is_array($data) ? array_values(array_map('strval', array_keys($data))) : [],
        ];
    }

    /** @return array{file: string, line: int, className: string}|null */
    protected function frameworkClassSourceFrame(?string $className): ?array
    {
        if ($className === null || $className === '' || ! class_exists($className)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($className);
            $file = $reflection->getFileName();
            $line = $reflection->getStartLine();

            if (! is_string($file) || $file === '' || ! is_int($line)) {
                return null;
            }

            $normalizedFile = str_replace('\\', '/', $file);
            $projectRoot = function_exists('base_path')
                ? rtrim(str_replace('\\', '/', base_path()), '/').'/'
                : null;

            if ($projectRoot !== null && str_starts_with($normalizedFile, $projectRoot)) {
                $normalizedFile = substr($normalizedFile, strlen($projectRoot));
            }

            return [
                'file' => $normalizedFile,
                'line' => max($line, 1),
                'className' => $className,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, string> */
    protected function mailAddressLabels(mixed $addresses): array
    {
        if (! is_iterable($addresses)) {
            return [];
        }

        $labels = [];

        foreach ($addresses as $address) {
            $email = $this->safeMethodValue($address, 'getAddress');
            $name = $this->safeMethodValue($address, 'getName');

            if (! is_scalar($email) || (string) $email === '') {
                continue;
            }

            $labels[] = is_scalar($name) && (string) $name !== ''
                ? (string) $name.' <'.(string) $email.'>'
                : (string) $email;
        }

        return $labels;
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    protected function eventClassName(string $eventName, array $payload): ?string
    {
        foreach ($payload as $item) {
            if (is_object($item)) {
                return $item::class;
            }
        }

        return class_exists($eventName) ? $eventName : null;
    }

    /** @param  array<int, mixed>  $payload */
    protected function eventPayloadPreview(array $payload): string
    {
        $normalized = array_map(static function (mixed $value): mixed {
            if (is_scalar($value) || $value === null) {
                return $value;
            }

            if (is_object($value)) {
                return ['class' => $value::class];
            }

            if (is_array($value)) {
                return ['count' => count($value)];
            }

            return get_debug_type($value);
        }, array_slice($payload, 0, 5));

        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_string($json) || $json === '') {
            return '[]';
        }

        return Str::limit($json, 240, '...');
    }

    protected function eventCategory(string $eventName): string
    {
        return match (true) {
            Str::startsWith($eventName, ['bootstrapping:', 'bootstrapped:']) => 'bootstrap',
            str_contains($eventName, 'Routing\\'),
            str_contains($eventName, 'Route'),
            str_contains($eventName, 'Matched') => 'routing',
            str_contains($eventName, 'Middleware') => 'middleware',
            str_contains($eventName, 'View'),
            str_contains($eventName, 'Composer') => 'view',
            $this->isBroadcastEvent($eventName, null) => 'broadcast',
            str_starts_with($eventName, 'eloquent'),
            str_contains($eventName, 'Eloquent') => 'eloquent',
            str_contains($eventName, 'Cache') => 'cache',
            str_contains($eventName, 'Batch') => 'batch',
            str_contains($eventName, 'Queue'),
            str_contains($eventName, 'Job') => 'queue',
            str_contains($eventName, 'Mail') => 'mail',
            str_contains($eventName, 'Notification') => 'notification',
            str_contains($eventName, 'Command') => 'command',
            str_contains($eventName, 'ScheduledTask') => 'schedule',
            str_contains($eventName, 'Gate') => 'gate',
            str_contains($eventName, 'Redis') => 'redis',
            str_contains($eventName, 'MessageLogged') => 'log',
            str_contains($eventName, 'Http\\Client'),
            str_contains($eventName, 'ResponseReceived'),
            str_contains($eventName, 'ConnectionFailed') => 'http-client',
            str_starts_with($eventName, 'Illuminate\\') => 'app',
            default => 'unknown',
        };
    }
}
