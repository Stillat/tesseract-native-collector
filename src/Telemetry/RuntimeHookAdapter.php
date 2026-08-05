<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry;

use Native\Mobile\Edge\Contracts\RuntimeObserver;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Runtime\ComponentContext;
use Native\Mobile\Edge\Runtime\ComponentPublished;
use Native\Mobile\Edge\Runtime\Dispatch;
use Native\Mobile\Edge\Runtime\DispatchFinished;
use Native\Mobile\Edge\Runtime\DispatchKind;
use Native\Mobile\Edge\Runtime\DispatchStarting;
use Native\Mobile\Edge\Runtime\RuntimeFailed;
use Native\Mobile\Edge\RuntimeObservers;
use ReflectionObject;
use Throwable;

/**
 * Adapts NativePHP's typed runtime observer to the collector's telemetry
 * callbacks. This keeps correlation and envelope shaping inside the package.
 */
final class RuntimeHookAdapter implements RuntimeObserver
{
    /** @var array<int, callable(array): void> */
    private static array $scopeObservers = [];

    /** @var array<int, callable(array): void> */
    private static array $interactionWillObservers = [];

    /** @var array<int, callable(array): void> */
    private static array $interactionDidObservers = [];

    /** @var array<int, callable(array): void> */
    private static array $nativeWillObservers = [];

    /** @var array<int, callable(array): void> */
    private static array $nativeDidObservers = [];

    /** @var array<int, callable(Throwable, string): void> */
    private static array $failureObservers = [];

    private static int $sequence = 0;

    private static ?int $runtimeSubscription = null;

    /** @var array<string, array<string, mixed>> */
    private static array $dispatchStates = [];

    public static function boot(): void
    {
        if (self::$runtimeSubscription !== null) {
            RuntimeObservers::unregister(self::$runtimeSubscription);
        }

        self::$runtimeSubscription = RuntimeObservers::register(new self);
    }

    public static function reset(): void
    {
        if (self::$runtimeSubscription !== null) {
            RuntimeObservers::unregister(self::$runtimeSubscription);
        }

        self::$scopeObservers = [];
        self::$interactionWillObservers = [];
        self::$interactionDidObservers = [];
        self::$nativeWillObservers = [];
        self::$nativeDidObservers = [];
        self::$failureObservers = [];
        self::$dispatchStates = [];
        self::$sequence = 0;
        self::$runtimeSubscription = null;
    }

    public static function observeScopePublished(callable $observer): int
    {
        return self::add(self::$scopeObservers, $observer);
    }

    public static function stopObservingScopePublished(int $id): void
    {
        unset(self::$scopeObservers[$id]);
    }

    public static function observeInteractionWillDispatch(callable $observer): int
    {
        return self::add(self::$interactionWillObservers, $observer);
    }

    public static function stopObservingInteractionWillDispatch(int $id): void
    {
        unset(self::$interactionWillObservers[$id]);
    }

    public static function observeInteractionDispatched(callable $observer): int
    {
        return self::add(self::$interactionDidObservers, $observer);
    }

    public static function stopObservingInteractionDispatched(int $id): void
    {
        unset(self::$interactionDidObservers[$id]);
    }

    public static function observeNativeEventWillDispatch(callable $observer): int
    {
        return self::add(self::$nativeWillObservers, $observer);
    }

    public static function stopObservingNativeEventWillDispatch(int $id): void
    {
        unset(self::$nativeWillObservers[$id]);
    }

    public static function observeNativeEventDispatched(callable $observer): int
    {
        return self::add(self::$nativeDidObservers, $observer);
    }

    public static function stopObservingNativeEventDispatched(int $id): void
    {
        unset(self::$nativeDidObservers[$id]);
    }

    public static function observeRenderError(callable $observer): int
    {
        return self::add(self::$failureObservers, $observer);
    }

    public static function stopObservingRenderError(int $id): void
    {
        unset(self::$failureObservers[$id]);
    }

    public function componentPublished(ComponentPublished $event): void
    {
        $timings = $event->timings;

        self::notify(self::$scopeObservers, [
            ...self::contextPayload($event->context),
            'state' => self::componentState($event->context->component),
            'timings' => $timings === null ? null : [
                'renderMs' => $timings->renderMs,
                'serializeMs' => $timings->serializeMs,
                'publishMs' => $timings->publishMs,
            ],
        ]);
    }

    public function dispatchStarting(DispatchStarting $event): void
    {
        $dispatch = $event->dispatch;
        $state = self::componentState($dispatch->context->component);
        self::$dispatchStates[self::dispatchKey($dispatch)] = $state;
        $normalized = self::normalizeDispatch([
            ...self::dispatchPayload($dispatch),
            'before' => $state,
        ]);

        self::notify(
            $dispatch->kind === DispatchKind::Native
                ? self::$nativeWillObservers
                : self::$interactionWillObservers,
            $normalized,
        );
    }

    public function dispatchFinished(DispatchFinished $event): void
    {
        $dispatch = $event->dispatch;
        $key = self::dispatchKey($dispatch);
        $before = self::$dispatchStates[$key] ?? [];
        unset(self::$dispatchStates[$key]);

        $normalized = self::normalizeDispatch([
            ...self::dispatchPayload($dispatch),
            'before' => $before,
            'after' => self::componentState($dispatch->context->component),
            'durationMs' => $event->durationMs,
            'error' => $event->exception,
        ]);

        self::notify(
            $dispatch->kind === DispatchKind::Native
                ? self::$nativeDidObservers
                : self::$interactionDidObservers,
            $normalized,
        );
    }

    public function failed(RuntimeFailed $event): void
    {
        foreach (self::$failureObservers as $observer) {
            try {
                $observer($event->exception, $event->context->component::class);
            } catch (Throwable) {
                // Telemetry must never change application behavior.
            }
        }
    }

    /** @param array<int, callable> $bucket */
    private static function add(array &$bucket, callable $observer): int
    {
        $id = ++self::$sequence;
        $bucket[$id] = $observer;

        return $id;
    }

    /** @param array<int, callable(array): void> $observers */
    private static function notify(array $observers, array $payload): void
    {
        foreach ($observers as $observer) {
            try {
                $observer($payload);
            } catch (Throwable) {
                // Telemetry must never change application behavior.
            }
        }
    }

    private static function normalizeDispatch(array $dispatch): array
    {
        $error = $dispatch['error'] ?? null;

        return [
            ...$dispatch,
            'eventType' => is_int($dispatch['type'] ?? null) ? $dispatch['type'] : -1,
            'stateBefore' => is_array($dispatch['before'] ?? null) ? $dispatch['before'] : [],
            'stateAfter' => is_array($dispatch['after'] ?? null) ? $dispatch['after'] : [],
            'error' => $error instanceof Throwable ? [
                'class' => $error::class,
                'message' => $error->getMessage(),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function contextPayload(ComponentContext $context): array
    {
        $class = $context->component::class;

        return [
            'id' => spl_object_hash($context->component),
            'name' => class_basename($class),
            'class' => $class,
            'uri' => $context->uri,
            'renderCount' => $context->renderCount,
        ];
    }

    /** @return array<string, mixed> */
    private static function dispatchPayload(Dispatch $dispatch): array
    {
        $payload = [
            'kind' => $dispatch->kind->value,
            ...self::contextPayload($dispatch->context),
            'method' => $dispatch->method,
        ];

        if ($dispatch->kind === DispatchKind::Native) {
            return [
                ...$payload,
                'event' => $dispatch->event,
                'payload' => $dispatch->payload,
            ];
        }

        return [
            ...$payload,
            'type' => $dispatch->eventType,
            'callbackId' => $dispatch->callbackId,
            'nodeId' => $dispatch->nodeId,
            'args' => $dispatch->arguments,
        ];
    }

    private static function dispatchKey(Dispatch $dispatch): string
    {
        return spl_object_id($dispatch->context->component).':'.$dispatch->id;
    }

    /** @return array<string, mixed> */
    private static function componentState(NativeComponent $component): array
    {
        $state = [];

        foreach ((new ReflectionObject($component))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || ! $property->isInitialized($component)) {
                continue;
            }

            try {
                $state[$property->getName()] = $property->getValue($component);
            } catch (Throwable) {
                // A single unreadable property must not suppress the snapshot.
            }
        }

        return $state;
    }
}
