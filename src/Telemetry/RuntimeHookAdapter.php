<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry;

use Native\Mobile\Edge\Contracts\RuntimeObserver;
use Native\Mobile\Edge\RuntimeObservers;
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

    public function componentPublished(array $snapshot): void
    {
        self::notify(self::$scopeObservers, $snapshot);
    }

    public function dispatchStarting(array $dispatch): void
    {
        $normalized = self::normalizeDispatch($dispatch);
        self::notify(
            ($dispatch['kind'] ?? null) === 'native'
                ? self::$nativeWillObservers
                : self::$interactionWillObservers,
            $normalized,
        );
    }

    public function dispatchFinished(array $dispatch): void
    {
        $normalized = self::normalizeDispatch($dispatch);
        self::notify(
            ($dispatch['kind'] ?? null) === 'native'
                ? self::$nativeDidObservers
                : self::$interactionDidObservers,
            $normalized,
        );
    }

    public function failed(Throwable $exception, array $context): void
    {
        foreach (self::$failureObservers as $observer) {
            try {
                $observer($exception, is_string($context['class'] ?? null) ? $context['class'] : '');
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
}
