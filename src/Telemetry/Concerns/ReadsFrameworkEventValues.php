<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry\Concerns;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

trait ReadsFrameworkEventValues
{
    protected function isBroadcastEvent(string $eventName, mixed $event): bool
    {
        if ($event instanceof ShouldBroadcast) {
            return true;
        }

        return class_exists($eventName)
            && in_array(ShouldBroadcast::class, (array) class_implements($eventName), true);
    }

    /**
     * @return array<int, string>
     */
    protected function broadcastChannels(mixed $channels): array
    {
        $labels = [];

        foreach (is_array($channels) ? $channels : [$channels] as $channel) {
            if (is_array($channel)) {
                array_push($labels, ...$this->broadcastChannels($channel));

                continue;
            }

            $label = $this->broadcastChannelLabel($channel);

            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    protected function broadcastChannelLabel(mixed $channel): ?string
    {
        if (is_scalar($channel) && (string) $channel !== '') {
            return (string) $channel;
        }

        if (! is_object($channel)) {
            return null;
        }

        $name = $this->publicProperty($channel, 'name');

        if (is_scalar($name) && (string) $name !== '') {
            return (string) $name;
        }

        if (method_exists($channel, '__toString')) {
            try {
                $label = (string) $channel;

                if ($label !== '') {
                    return $label;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return $channel::class;
    }

    /**
     * @return array<int, string>
     */
    protected function broadcastConnections(mixed $connections): array
    {
        $labels = [];

        foreach (is_array($connections) ? $connections : [$connections] as $connection) {
            if (is_scalar($connection) && (string) $connection !== '') {
                $labels[] = (string) $connection;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function broadcastPayload(mixed $event): ?array
    {
        $payload = $this->safeMethodValue($event, 'broadcastWith');

        if (is_array($payload)) {
            $socket = $this->publicProperty($event, 'socket');

            if (is_scalar($socket) && (string) $socket !== '' && ! array_key_exists('socket', $payload)) {
                $payload['socket'] = (string) $socket;
            }

            return $payload;
        }

        if (! is_object($event)) {
            return null;
        }

        $payload = [];

        foreach ((new ReflectionClass($event))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getName() === 'broadcastQueue') {
                continue;
            }

            try {
                $payload[$property->getName()] = $property->getValue($event);
            } catch (Throwable) {
                continue;
            }
        }

        return $payload !== [] ? $payload : null;
    }

    protected function publicProperty(mixed $object, string $property): mixed
    {
        if (! is_object($object) || ! property_exists($object, $property)) {
            return null;
        }

        try {
            return $object->{$property};
        } catch (Throwable) {
            return null;
        }
    }

    protected function safePropertyValue(mixed $object, string $property): mixed
    {
        if (! is_object($object)) {
            return null;
        }

        try {
            return $object->{$property};
        } catch (Throwable) {
            return null;
        }
    }

    protected function safeMethodValue(mixed $object, string $method): mixed
    {
        if (! is_object($object) || ! method_exists($object, $method)) {
            return null;
        }

        try {
            return $object->{$method}();
        } catch (Throwable) {
            return null;
        }
    }

    protected function stringOrDefault(mixed $value, string $default): string
    {
        return is_scalar($value) && (string) $value !== ''
            ? (string) $value
            : $default;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function queuePayload(mixed $event, mixed $job): ?array
    {
        $payload = $this->publicProperty($event, 'payload')
            ?? $this->safeMethodValue($job, 'payload');

        if (is_string($payload) && $payload !== '') {
            try {
                $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

                return is_array($decoded) ? $decoded : null;
            } catch (Throwable) {
                return ['raw' => $payload];
            }
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function queueJobName(mixed $event, mixed $job, ?array $payload): string
    {
        $resolved = $this->safeMethodValue($job, 'resolveName')
            ?? $this->safeMethodValue($job, 'displayName');

        if (is_scalar($resolved) && (string) $resolved !== '') {
            return (string) $resolved;
        }

        $commandName = $payload['displayName']
            ?? $payload['job']
            ?? $payload['data']['commandName']
            ?? null;

        if (is_scalar($commandName) && (string) $commandName !== '') {
            return (string) $commandName;
        }

        $queuedJob = $this->publicProperty($event, 'job');

        if (is_object($queuedJob)) {
            return $queuedJob::class;
        }

        if (is_string($queuedJob) && $queuedJob !== '') {
            return $queuedJob;
        }

        return '';
    }
}
