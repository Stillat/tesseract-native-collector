<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry\Concerns;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Symfony\Component\VarDumper\VarDumper;
use Throwable;

trait ForwardsLaravelTelemetry
{
    public function subscribe(Dispatcher $events): void
    {
        if ($this->dispatcher !== null) {
            return;
        }

        $this->dispatcher = $events;

        if ((bool) config('tesseract-native.telemetry.logs', true)) {
            $events->listen(MessageLogged::class, function (MessageLogged $event): void {
                $this->forward($this->envelopes->log(
                    $event,
                    $this->currentRequestId,
                    $this->currentTraceId,
                    $this->currentParentRequestId,
                    $this->currentStage,
                ));

                if (
                    (bool) config('tesseract-native.telemetry.exceptions', true)
                    && ($event->context['exception'] ?? null) instanceof Throwable
                ) {
                    $this->forward($this->envelopes->exception(
                        $event->context['exception'],
                        (string) $event->level,
                        $this->currentRequestId,
                        null,
                        $this->currentTraceId,
                        $this->currentParentRequestId,
                        $this->currentStage,
                    ));
                }
            });
        }

        if ((bool) config('tesseract-native.telemetry.queries', true)) {
            $events->listen(QueryExecuted::class, function (QueryExecuted $event): void {
                if ($this->frameworkEvents?->isCollectorInfrastructureQuery($event->sql) === true) {
                    return;
                }

                $this->forward($this->envelopes->query(
                    $event,
                    $this->currentRequestId,
                    $this->currentTraceId,
                    $this->currentParentRequestId,
                    $this->currentStage,
                ));
            });
        }

        if ((bool) config('tesseract-native.telemetry.network', true)) {
            $events->listen(ResponseReceived::class, function (ResponseReceived $event): void {
                $this->forward($this->envelopes->clientResponse(
                    $event,
                    $this->currentRequestId,
                    $this->currentTraceId,
                    $this->currentParentRequestId,
                    $this->currentStage,
                ));
            });

            $events->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
                $this->forward($this->envelopes->clientFailed(
                    $event,
                    $this->currentRequestId,
                    $this->currentTraceId,
                    $this->currentParentRequestId,
                    $this->currentStage,
                ));
            });
        }

        if ((bool) config('tesseract-native.telemetry.events', true)) {
            $events->listen('*', function (string $eventName, array $payload): void {
                try {
                    $this->forwardFrameworkEvent($eventName, $payload);
                } catch (Throwable) {
                }
            });
        }

        if ((bool) config('tesseract-native.telemetry.dumps', true)) {
            $this->registerDumpHandler();
        }

        $this->bindNativeComponents();
    }

    protected function registerDumpHandler(): void
    {
        self::$activeDumpForwarder = $this;

        if (self::$dumpHandlerInstalled) {
            return;
        }

        self::$dumpHandlerInstalled = true;
        $previousHandler = null;
        $handler = function (mixed $value, ?string $label = null) use (&$previousHandler): void {
            $forwarder = self::$activeDumpForwarder;

            if ($forwarder !== null) {
                try {
                    $forwarder->forward($forwarder->envelopes->dump(
                        $value,
                        $label,
                        $forwarder->currentRequestId,
                        $forwarder->currentTraceId,
                        $forwarder->currentParentRequestId,
                        $forwarder->currentStage,
                    ));
                } catch (Throwable) {
                }
            }

            if (is_callable($previousHandler)) {
                $previousHandler($value, $label);
            }
        };
        $previousHandler = VarDumper::setHandler($handler);
    }

    /** @param  array<int, mixed>  $payload */
    protected function forwardFrameworkEvent(string $eventName, array $payload): void
    {
        if ($this->isMuted()) {
            return;
        }

        if (! $this->frameworkEvents->accepts($eventName, $payload, $this->currentRequestId)) {
            $reason = $this->frameworkEvents->rejectionReason();

            if ($reason !== 'filtered-noise') {
                $this->recordTelemetryDrop('framework-events', $reason ?? 'rejected', $eventName);
            }

            return;
        }

        $this->flushTelemetryDrops();

        $this->forward($this->envelopes->frameworkEvent(
            $eventName,
            $payload,
            $this->listenerLabels($eventName),
            $this->currentRequestId,
            $this->frameworkEvents->cacheOperationDurationMs($payload[0] ?? null),
            $this->currentTraceId,
            $this->currentParentRequestId,
            $this->currentStage,
        ));
    }

    /** @return array<int, string> */
    protected function listenerLabels(string $eventName): array
    {
        $labels = [];

        foreach ($this->listenersFor($eventName) as $listener) {
            if (count($labels) >= 10) {
                break;
            }

            if (is_array($listener) && count($listener) === 2) {
                $class = is_object($listener[0]) ? $listener[0]::class : (string) $listener[0];
                $method = is_string($listener[1]) ? $listener[1] : '__invoke';

                $labels[] = $class.'@'.$method;

                continue;
            }

            if ($listener instanceof Closure) {
                $labels[] = 'Closure';

                continue;
            }

            if (is_object($listener)) {
                $labels[] = $listener::class;

                continue;
            }

            if (is_string($listener)) {
                $labels[] = $listener;
            }
        }

        return $labels;
    }

    /** @return array<int, mixed> */
    protected function listenersFor(string $eventName): array
    {
        if ($this->dispatcher === null) {
            return [];
        }

        if (method_exists($this->dispatcher, 'getRawListeners')) {
            try {
                $raw = $this->dispatcher->getRawListeners()[$eventName] ?? [];

                return is_array($raw) ? array_values($raw) : [];
            } catch (Throwable) {
                return [];
            }
        }

        if (! method_exists($this->dispatcher, 'getListeners')) {
            return [];
        }

        try {
            return $this->dispatcher->getListeners($eventName);
        } catch (Throwable) {
            return [];
        }
    }
}
