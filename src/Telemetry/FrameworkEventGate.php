<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Str;
use Throwable;

class FrameworkEventGate
{
    /** @var array<string, list<int>> */
    protected array $cacheOperationStartedAt = [];

    /** @var array<int, float> */
    protected array $cacheOperationDurations = [];

    protected const MaxEventsPerRequest = 100;

    protected const MaxEventsPerSecond = 50;

    protected const MaxGeneralEventsPerRequest = 80;

    protected const MaxGeneralEventsPerSecond = 40;

    protected const NullBucketFallbackSeconds = 60;

    protected const MaxPendingCacheOperationKeys = 256;

    protected const MaxPendingCacheOperationsPerKey = 8;

    protected const DedicatedListenerEvents = [
        QueryExecuted::class,
        MessageLogged::class,
        ResponseReceived::class,
        ConnectionFailed::class,
    ];

    protected const PriorityEventPrefixes = [
        'Illuminate\\Queue\\Events\\',
        'Illuminate\\Broadcasting\\Events\\',
        'Illuminate\\Mail\\Events\\',
        'Illuminate\\Notifications\\Events\\',
        'Illuminate\\Bus\\Events\\Batch',
        'Illuminate\\Console\\Events\\Command',
        'Illuminate\\Console\\Events\\ScheduledTask',
        'Illuminate\\Console\\Events\\ScheduledBackgroundTask',
        'Illuminate\\Auth\\Access\\Events\\Gate',
        'Illuminate\\Redis\\Events\\',
        'composing:',
    ];

    protected ?string $bucketRequestId = null;

    protected int $bucketCount = 0;

    protected int $bucketGeneralCount = 0;

    protected int $bucketStartedAtSecond = 0;

    protected int $windowSecond = 0;

    protected int $windowCount = 0;

    protected int $windowGeneralCount = 0;

    protected ?string $suppressedTablePattern = null;

    protected bool $suppressedTablePatternBuilt = false;

    protected ?string $lastRejectionReason = null;

    /**
     * @param  array<int, mixed>  $payload
     */
    public function accepts(string $eventName, array $payload, ?string $requestId): bool
    {
        $event = $payload[0] ?? null;
        $this->observeCacheOperation($eventName, $event);

        $this->lastRejectionReason = null;

        if (
            Str::startsWith($eventName, 'Illuminate\\Mail\\Events\\')
            && config('tesseract-native.observability.mail.capture', 'preview') === 'off'
        ) {
            $this->lastRejectionReason = 'mail-capture-disabled';
            $this->discardCacheOperationDuration($event);

            return false;
        }

        if ($this->isCollectorNoise($eventName, $payload)) {
            $this->lastRejectionReason = 'filtered-noise';
            $this->discardCacheOperationDuration($event);

            return false;
        }

        $accepted = $this->claimSlot(
            $requestId,
            $this->isPriorityEvent($eventName, $payload),
        );

        if (! $accepted) {
            $this->discardCacheOperationDuration($event);
        }

        return $accepted;
    }

    public function rejectionReason(): ?string
    {
        return $this->lastRejectionReason;
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    protected function isCollectorNoise(string $eventName, array $payload): bool
    {
        if ($eventName === '') {
            return true;
        }

        if (str_starts_with($eventName, 'Tesseract\\NativeCollector\\')) {
            return true;
        }

        $first = $payload[0] ?? null;

        if (is_object($first) && str_starts_with($first::class, 'Tesseract\\NativeCollector\\')) {
            return true;
        }

        if ($this->isBootstrapNoise($eventName)) {
            return true;
        }

        if ($eventName === JobQueueing::class || $first instanceof JobQueueing) {
            return true;
        }

        if ($this->isCachePreparationEvent($eventName, $first)) {
            return true;
        }

        if ($this->isDedicatedListenerEvent($eventName, $first)) {
            return true;
        }

        if ($this->isSuppressedQueryShapedEvent($first)) {
            return true;
        }

        if (str_starts_with($eventName, 'Illuminate\\Queue\\Events\\') && $this->isSuppressedQueueEvent($first)) {
            return true;
        }

        if (str_starts_with($eventName, 'Illuminate\\Cache\\Events\\') && $this->isSuppressedCacheEvent($first)) {
            return true;
        }

        return false;
    }

    protected function isDedicatedListenerEvent(string $eventName, mixed $event): bool
    {
        if (in_array($eventName, self::DedicatedListenerEvents, true)) {
            return true;
        }

        foreach (self::DedicatedListenerEvents as $eventClass) {
            if ($event instanceof $eventClass) {
                return true;
            }
        }

        return false;
    }

    protected function isSuppressedQueryShapedEvent(mixed $event): bool
    {
        $sql = $this->eventProperty($event, 'sql');

        return is_string($sql) && $this->isCollectorInfrastructureQuery($sql);
    }

    public function isCollectorInfrastructureQuery(string $sql): bool
    {
        $pattern = $this->suppressedTablePattern();

        return $pattern !== null && preg_match($pattern, $sql) === 1;
    }

    protected function suppressedTablePattern(): ?string
    {
        if ($this->suppressedTablePatternBuilt) {
            return $this->suppressedTablePattern;
        }

        $this->suppressedTablePatternBuilt = true;

        /** @var array<int, string> $tables */
        $tables = (array) config('tesseract-native.pump.suppress_query_tables', [
            'jobs',
            'job_batches',
            'failed_jobs',
            'cache',
            'cache_locks',
            'migrations',
            'sqlite_master',
        ]);

        $quoted = [];

        foreach ($tables as $table) {
            if (is_string($table) && $table !== '') {
                $quoted[] = preg_quote($table, '/');
            }
        }

        if ($quoted === []) {
            return $this->suppressedTablePattern = null;
        }

        return $this->suppressedTablePattern = '/(^|[\s,."\'`(])('.implode('|', $quoted).')(?=$|["`\s,;().])/i';
    }

    protected function isBootstrapNoise(string $eventName): bool
    {
        return Str::startsWith($eventName, [
            'bootstrapping:',
            'bootstrapped:',
            'creating:',
        ]);
    }

    protected function isCachePreparationEvent(string $eventName, mixed $event): bool
    {
        $eventClass = is_object($event) ? $event::class : $eventName;

        return str_starts_with($eventClass, 'Illuminate\\Cache\\Events\\')
            && in_array(class_basename($eventClass), [
                'WritingKey',
                'WritingManyKeys',
                'ForgettingKey',
                'CacheFlushing',
            ], true);
    }

    public function cacheOperationDurationMs(mixed $event): ?float
    {
        if (! is_object($event)) {
            return null;
        }

        $eventId = spl_object_id($event);
        $durationMs = $this->cacheOperationDurations[$eventId] ?? null;

        unset($this->cacheOperationDurations[$eventId]);

        return $durationMs;
    }

    protected function discardCacheOperationDuration(mixed $event): void
    {
        if (is_object($event)) {
            unset($this->cacheOperationDurations[spl_object_id($event)]);
        }
    }

    protected function observeCacheOperation(string $eventName, mixed $event): void
    {
        if (! is_object($event) || ! str_starts_with($eventName, 'Illuminate\\Cache\\Events\\')) {
            return;
        }

        $eventType = class_basename($eventName);
        $operation = match ($eventType) {
            'WritingKey', 'WritingManyKeys', 'KeyWritten', 'KeyWriteFailed' => 'put',
            'ForgettingKey', 'KeyForgotten', 'KeyForgetFailed' => 'forget',
            'CacheFlushing', 'CacheFlushed', 'CacheFlushFailed' => 'flush',
            default => null,
        };

        if ($operation === null) {
            return;
        }

        $store = $this->eventProperty($event, 'storeName');
        $keys = $eventType === 'WritingManyKeys'
            ? $this->eventProperty($event, 'keys')
            : [$this->eventProperty($event, 'key') ?? '*'];
        $keys = is_array($keys) ? $keys : ['*'];

        if (in_array($eventType, ['WritingKey', 'WritingManyKeys', 'ForgettingKey', 'CacheFlushing'], true)) {
            $startedAt = hrtime(true);

            foreach ($keys as $key) {
                $operationKey = $this->cacheOperationKey($operation, $store, $key);
                $this->rememberCacheOperationStart($operationKey, $startedAt);
            }

            return;
        }

        $operationKey = $this->cacheOperationKey($operation, $store, $keys[0] ?? '*');
        $startedAt = isset($this->cacheOperationStartedAt[$operationKey])
            ? array_shift($this->cacheOperationStartedAt[$operationKey])
            : null;

        if (($this->cacheOperationStartedAt[$operationKey] ?? []) === []) {
            unset($this->cacheOperationStartedAt[$operationKey]);
        }

        if (is_int($startedAt)) {
            $this->cacheOperationDurations[spl_object_id($event)] = round((hrtime(true) - $startedAt) / 1_000_000, 3);
        }
    }

    protected function cacheOperationKey(string $operation, mixed $store, mixed $key): string
    {
        return implode(':', [
            $operation,
            is_scalar($store) ? (string) $store : 'default',
            is_scalar($key) ? (string) $key : '*',
        ]);
    }

    protected function rememberCacheOperationStart(string $operationKey, int $startedAt): void
    {
        if (! isset($this->cacheOperationStartedAt[$operationKey])) {
            if (count($this->cacheOperationStartedAt) >= self::MaxPendingCacheOperationKeys) {
                array_shift($this->cacheOperationStartedAt);
            }

            $this->cacheOperationStartedAt[$operationKey] = [];
        }

        if (count($this->cacheOperationStartedAt[$operationKey]) >= self::MaxPendingCacheOperationsPerKey) {
            array_shift($this->cacheOperationStartedAt[$operationKey]);
        }

        $this->cacheOperationStartedAt[$operationKey][] = $startedAt;
    }

    protected function isSuppressedQueueEvent(mixed $event): bool
    {
        $job = $this->eventProperty($event, 'job');

        if (! is_object($job)) {
            return false;
        }

        try {
            $jobClass = method_exists($job, 'resolveName') ? (string) $job->resolveName() : $job::class;
        } catch (Throwable) {
            $jobClass = $job::class;
        }

        return in_array($jobClass, $this->suppressedJobClasses(), true);
    }

    protected function isSuppressedCacheEvent(mixed $event): bool
    {
        $key = $this->eventProperty($event, 'key');

        if (! is_string($key) || $key === '') {
            return false;
        }

        if (str_starts_with($key, 'tesseract:')) {
            return true;
        }

        return in_array($key, $this->suppressedCacheKeys(), true);
    }

    /**
     * @return array<int, string>
     */
    protected function suppressedJobClasses(): array
    {
        $classes = (array) config('tesseract-native.pump.suppress_job_classes', [
            'Tesseract\\NativeCollector\\Jobs\\PumpTesseractCommands',
        ]);

        return array_values(array_filter($classes, 'is_string'));
    }

    /**
     * @return array<int, string>
     */
    protected function suppressedCacheKeys(): array
    {
        $keys = (array) config('tesseract-native.pump.suppress_cache_keys', [
            'tesseract:pump:alive',
            'illuminate:queue:restart',
        ]);

        return array_values(array_filter($keys, 'is_string'));
    }

    /** @param  array<int, mixed>  $payload */
    protected function isPriorityEvent(string $eventName, array $payload): bool
    {
        if (! str_starts_with($eventName, 'Illuminate\\')) {
            if (! str_starts_with($eventName, 'eloquent.')) {
                return true;
            }

            return preg_match('/^eloquent\.(created|deleted|forceDeleted|restored|saved|updated):/i', $eventName) === 1;
        }

        if (str_starts_with($eventName, 'Illuminate\\Cache\\Events\\')) {
            return in_array(class_basename($eventName), [
                'CacheHit',
                'CacheMissed',
                'KeyForgotten',
                'KeyWritten',
            ], true);
        }

        if (Str::startsWith($eventName, self::PriorityEventPrefixes)) {
            return true;
        }

        return ($payload[0] ?? null) instanceof ShouldBroadcast;
    }

    protected function claimSlot(?string $requestId, bool $priority): bool
    {
        $second = $this->nowSeconds();

        if ($requestId !== $this->bucketRequestId) {
            $this->bucketRequestId = $requestId;
            $this->bucketCount = 0;
            $this->bucketGeneralCount = 0;
            $this->bucketStartedAtSecond = $second;
        }

        if ($requestId === null && $second - $this->bucketStartedAtSecond >= self::NullBucketFallbackSeconds) {
            $this->bucketCount = 0;
            $this->bucketGeneralCount = 0;
            $this->bucketStartedAtSecond = $second;
        }

        if ($this->bucketCount >= self::MaxEventsPerRequest) {
            $this->lastRejectionReason = 'per-request-limit';

            return false;
        }

        if ($second !== $this->windowSecond) {
            $this->windowSecond = $second;
            $this->windowCount = 0;
            $this->windowGeneralCount = 0;
        }

        if ($this->windowCount >= self::MaxEventsPerSecond) {
            $this->lastRejectionReason = 'per-second-limit';

            return false;
        }

        if (! $priority && $this->bucketGeneralCount >= self::MaxGeneralEventsPerRequest) {
            $this->lastRejectionReason = 'per-request-limit';

            return false;
        }

        if (! $priority && $this->windowGeneralCount >= self::MaxGeneralEventsPerSecond) {
            $this->lastRejectionReason = 'per-second-limit';

            return false;
        }

        $this->bucketCount++;
        $this->windowCount++;

        if (! $priority) {
            $this->bucketGeneralCount++;
            $this->windowGeneralCount++;
        }

        return true;
    }

    protected function eventProperty(mixed $event, string $property): mixed
    {
        if (! is_object($event) || ! property_exists($event, $property)) {
            return null;
        }

        try {
            return $event->{$property};
        } catch (Throwable) {
            return null;
        }
    }

    protected function nowSeconds(): int
    {
        return (int) floor(microtime(true));
    }
}
