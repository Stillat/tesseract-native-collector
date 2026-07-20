<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Tesseract\NativeCollector\NativeAgent;
use Tesseract\NativeCollector\Telemetry\Concerns\ForwardsLaravelTelemetry;
use Throwable;

class TelemetryForwarder
{
    use ForwardsLaravelTelemetry;

    protected bool $forwarding = false;

    /** @var array<string, array{class: class-string, stop: string, id: int}> */
    protected static array $nativeHookSubscriptions = [];

    protected static bool $suppressed = false;

    protected static ?self $activeDumpForwarder = null;

    protected static bool $dumpHandlerInstalled = false;

    protected ?string $currentRequestId = null;

    protected ?string $navigationRequestId = null;

    protected ?string $currentTraceId = null;

    protected ?string $navigationTraceId = null;

    protected ?string $currentParentRequestId = null;

    protected ?string $currentStage = null;

    /** @var list<array{requestId: string, parentRequestId: string|null, started: bool}> */
    protected array $interactionRequestStack = [];

    /**
     * @var list<array{
     *     requestId: string,
     *     parentRequestId: string|null,
     *     traceId: string,
     *     started: bool,
     *     previousRequestId: string|null,
     *     previousParentRequestId: string|null,
     *     previousTraceId: string|null,
     *     previousStage: string|null
     * }>
     */
    protected array $nativeEventRequestStack = [];

    /** @var array<string, array{total: int, reasons: array<string, int>, samples: array<int, string>}> */
    protected array $telemetryDrops = [];

    protected ?Dispatcher $dispatcher = null;

    public function __construct(
        protected NativeAgent $agent,
        protected EnvelopeFactory $envelopes,
        protected ?FrameworkEventGate $frameworkEvents = null,
    ) {
        $this->frameworkEvents ??= new FrameworkEventGate;
    }

    public static function suppress(): void
    {
        self::$suppressed = true;
    }

    public static function resume(): void
    {
        self::$suppressed = false;
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressed;
    }

    public function broadcastRoutes(): void
    {
        if (! (bool) config('tesseract-native.telemetry.routes', true)) {
            return;
        }

        $routes = $this->collectNativeRoutes();

        if ($routes === []) {
            return;
        }

        $this->forward($this->envelopes->routes($routes));
    }

    /** @return array<int, array{path: string, name: string, component: string}> */
    protected function collectNativeRoutes(): array
    {
        $routerClass = 'Native\Mobile\Edge\NativeRouter';

        if (! class_exists($routerClass)) {
            return [];
        }

        try {
            $property = new \ReflectionProperty($routerClass, 'routes');
            $property->setAccessible(true);
            $raw = $property->getValue();
        } catch (Throwable) {
            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $routes = [];

        foreach ($raw as $path => $meta) {
            $class = is_array($meta) && is_string($meta['class'] ?? null) ? $meta['class'] : '';
            $routes[] = [
                'path' => (string) $path,
                'name' => $class !== '' ? class_basename($class) : (string) $path,
                'component' => $class,
            ];
        }

        return $routes;
    }

    public function bindNativeComponents(): void
    {
        self::clearNativeHookSubscriptions();

        $this->enableElementDebugCapture();

        $trackComponents = (bool) config('tesseract-native.telemetry.components', true);
        $trackNavigation = (bool) config('tesseract-native.telemetry.navigation', true);

        /** @var class-string $componentClass */
        $componentClass = 'Native\Mobile\Edge\NativeComponent';

        if (! class_exists($componentClass)) {
            return;
        }

        if ((! $trackComponents && ! $trackNavigation) || ! method_exists($componentClass, 'observeScopePublished')) {
            $this->bindNativeInteractions();
            $this->bindNativeEvents();
            $this->bindRenderErrors();

            return;
        }

        $hashes = [];
        $lastUri = null;
        $navCounter = 0;
        $lifecycleCounter = 0;
        $trackLifecycle = (bool) config('tesseract-native.telemetry.component_lifecycle', true);
        /** @var array<string, string> $currentComponentIds id => class for the current screen */
        $currentComponentIds = [];

        $scopeObserverId = $componentClass::observeScopePublished(function (array $snapshot) use (
            &$hashes,
            &$lastUri,
            &$navCounter,
            &$lifecycleCounter,
            &$currentComponentIds,
            $trackComponents,
            $trackNavigation,
            $trackLifecycle,
        ): void {
            $this->currentStage = 'render';
            $uri = is_string($snapshot['uri'] ?? null) ? $snapshot['uri'] : '';
            $componentId = is_string($snapshot['id'] ?? null) ? $snapshot['id'] : '';
            $class = is_string($snapshot['class'] ?? null) ? $snapshot['class'] : '';
            $scope = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : [];

            $routeChanged = $uri !== '' && $uri !== $lastUri;
            $componentChanged = $componentId !== ''
                && $currentComponentIds !== []
                && ! isset($currentComponentIds[$componentId]);

            if ($routeChanged || $componentChanged) {
                foreach ($currentComponentIds as $goneId => $goneClass) {
                    unset($hashes[$goneId]);

                    if ($goneId !== $componentId) {
                        $this->forward($this->envelopes->componentRemoved((string) $goneId));

                        if ($trackLifecycle) {
                            $requestId = 'life-'.(++$lifecycleCounter).'-'.substr(md5('u'.$goneId), 0, 8);
                            [$started, $finished] = $this->envelopes->componentLifecycle(
                                'unmount',
                                ['id' => $goneId, 'class' => $goneClass, 'uri' => $lastUri],
                                $requestId,
                                $this->currentTraceId,
                                $this->navigationRequestId,
                            );
                            $this->forward($started);
                            $this->forward($finished);
                        }
                    }
                }
                $currentComponentIds = [];
            }

            if ($routeChanged) {
                if ($trackNavigation) {
                    $this->flushTelemetryDrops();
                    $from = $lastUri;
                    $requestId = 'nav-'.(++$navCounter).'-'.substr(md5($uri), 0, 8);
                    $traceId = (string) Str::uuid();
                    $this->navigationRequestId = $requestId;
                    $this->navigationTraceId = $traceId;
                    $this->currentRequestId = $requestId;
                    $this->currentTraceId = $traceId;
                    $this->currentParentRequestId = null;
                    $this->forward($this->envelopes->navigationStarted($requestId, $uri, $from, $class, $traceId));
                    $this->forward($this->envelopes->navigationFinished($requestId, $uri, $class, $scope, 0.0, $from, $traceId));
                }

                $lastUri = $uri;
            }

            if ($trackLifecycle && $componentId !== '' && ! isset($currentComponentIds[$componentId])) {
                $requestId = 'life-'.(++$lifecycleCounter).'-'.substr(md5('m'.$componentId), 0, 8);
                [$started, $finished] = $this->envelopes->componentLifecycle(
                    'mount',
                    $snapshot,
                    $requestId,
                    $this->currentTraceId,
                    $this->navigationRequestId,
                );
                $this->forward($started);
                $this->forward($finished);
            }

            $currentComponentIds[$componentId] = $class;

            if (! $trackComponents) {
                $this->flushTelemetryDrops();
                $this->currentStage = null;

                return;
            }

            $envelope = $this->envelopes->component($snapshot);
            $id = is_string($envelope['payload']['componentId'] ?? null) ? $envelope['payload']['componentId'] : '';
            $hash = md5((string) json_encode($envelope['payload']['state']));

            if (($hashes[$id] ?? null) === $hash) {
                $this->flushTelemetryDrops();
                $this->currentStage = null;

                return;
            }

            $hashes[$id] = $hash;
            $this->forward($envelope);
            $this->flushTelemetryDrops();
            $this->currentStage = null;
        });
        self::rememberNativeHook('scope', $componentClass, 'stopObservingScopePublished', $scopeObserverId);

        $this->bindNativeInteractions();
        $this->bindNativeEvents();
        $this->bindRenderErrors();
    }

    protected function enableElementDebugCapture(): void
    {
        /** @var class-string $inspectorClass */
        $inspectorClass = 'Native\Mobile\Edge\Inspector\ElementInspector';

        if (class_exists($inspectorClass) && method_exists($inspectorClass, 'enable')) {
            $inspectorClass::enable();

            return;
        }

        /** @var class-string $elementClass */
        $elementClass = 'Native\Mobile\Edge\Element';

        if (class_exists($elementClass) && property_exists($elementClass, 'captureElementMetadata')) {
            (new \ReflectionProperty($elementClass, 'captureElementMetadata'))->setValue(null, true);
        }
    }

    protected function bindNativeInteractions(): void
    {
        if (! (bool) config('tesseract-native.telemetry.interactions', true)) {
            return;
        }

        /** @var class-string $componentClass */
        $componentClass = 'Native\Mobile\Edge\NativeComponent';

        if (! class_exists($componentClass) || ! method_exists($componentClass, 'observeInteractionDispatched')) {
            return;
        }

        $counter = 0;

        if (method_exists($componentClass, 'observeInteractionWillDispatch')) {
            $willObserverId = $componentClass::observeInteractionWillDispatch(function (array $interaction) use (&$counter): void {
                $requestId = $this->interactionRequestId($interaction, ++$counter);
                $parentRequestId = $this->currentRequestId;
                $traceId = $this->currentTraceId ?? $this->navigationTraceId ?? (string) Str::uuid();

                $this->interactionRequestStack[] = [
                    'requestId' => $requestId,
                    'parentRequestId' => $parentRequestId,
                    'started' => true,
                ];
                $this->currentRequestId = $requestId;
                $this->currentParentRequestId = $parentRequestId;
                $this->currentTraceId = $traceId;
                $this->currentStage = 'action';

                [$started] = $this->envelopes->interaction(
                    $interaction,
                    $requestId,
                    $traceId,
                    $parentRequestId,
                );
                $this->forward($started);
            });
            self::rememberNativeHook('interaction-will', $componentClass, 'stopObservingInteractionWillDispatch', $willObserverId);
        }

        $didObserverId = $componentClass::observeInteractionDispatched(function (array $interaction) use (&$counter): void {
            $context = array_pop($this->interactionRequestStack);
            $requestId = $context['requestId']
                ?? $this->interactionRequestId($interaction, ++$counter);
            $parentRequestId = $context['parentRequestId'] ?? $this->currentRequestId;
            $startedBeforeDispatch = $context['started'] ?? false;
            $traceId = $this->currentTraceId ?? $this->navigationTraceId ?? (string) Str::uuid();

            [$started, $finished] = $this->envelopes->interaction(
                $interaction,
                $requestId,
                $traceId,
                $parentRequestId,
            );

            $this->currentRequestId = $requestId;
            $this->currentParentRequestId = $parentRequestId;
            $this->currentTraceId = $traceId;
            $this->currentStage = 'action';

            try {
                if (! $startedBeforeDispatch) {
                    $this->forward($started);
                }

                $this->forward($finished);
                $this->flushTelemetryDrops();
            } finally {
                $outerContext = end($this->interactionRequestStack);
                $this->currentRequestId = is_array($outerContext)
                    ? $outerContext['requestId']
                    : $this->navigationRequestId;
                $this->currentParentRequestId = is_array($outerContext)
                    ? $outerContext['parentRequestId']
                    : null;
                $this->currentTraceId = $this->navigationTraceId ?? $traceId;
                $this->currentStage = is_array($outerContext) ? 'action' : null;
            }
        });
        self::rememberNativeHook('interaction-did', $componentClass, 'stopObservingInteractionDispatched', $didObserverId);
    }

    protected function bindNativeEvents(): void
    {
        if (! (bool) config('tesseract-native.telemetry.native_events', true)) {
            return;
        }

        /** @var class-string $componentClass */
        $componentClass = 'Native\Mobile\Edge\NativeComponent';

        if (! class_exists($componentClass) || ! method_exists($componentClass, 'observeNativeEventDispatched')) {
            return;
        }

        $counter = 0;

        if (method_exists($componentClass, 'observeNativeEventWillDispatch')) {
            $willObserverId = $componentClass::observeNativeEventWillDispatch(function (array $event) use (&$counter): void {
                $requestId = $this->nativeEventRequestId($event, ++$counter);
                $parentRequestId = $this->currentRequestId;
                $traceId = $this->currentTraceId ?? $this->navigationTraceId ?? (string) Str::uuid();

                $this->nativeEventRequestStack[] = [
                    'requestId' => $requestId,
                    'parentRequestId' => $parentRequestId,
                    'traceId' => $traceId,
                    'started' => true,
                    'previousRequestId' => $this->currentRequestId,
                    'previousParentRequestId' => $this->currentParentRequestId,
                    'previousTraceId' => $this->currentTraceId,
                    'previousStage' => $this->currentStage,
                ];
                $this->currentRequestId = $requestId;
                $this->currentParentRequestId = $parentRequestId;
                $this->currentTraceId = $traceId;
                $this->currentStage = 'action';

                [$started] = $this->envelopes->nativeEvent(
                    $event,
                    $requestId,
                    $traceId,
                    $parentRequestId,
                );
                $this->forward($started);
            });
            self::rememberNativeHook('native-event-will', $componentClass, 'stopObservingNativeEventWillDispatch', $willObserverId);
        }

        $didObserverId = $componentClass::observeNativeEventDispatched(function (array $event) use (&$counter): void {
            $context = array_pop($this->nativeEventRequestStack);
            $requestId = $context['requestId'] ?? $this->nativeEventRequestId($event, ++$counter);
            $parentRequestId = $context['parentRequestId'] ?? $this->currentRequestId;
            $traceId = $context['traceId'] ?? $this->currentTraceId ?? $this->navigationTraceId ?? (string) Str::uuid();
            $startedBeforeDispatch = $context['started'] ?? false;
            $previousRequestId = is_array($context) ? $context['previousRequestId'] : $this->currentRequestId;
            $previousParentRequestId = is_array($context) ? $context['previousParentRequestId'] : $this->currentParentRequestId;
            $previousTraceId = is_array($context) ? $context['previousTraceId'] : $this->currentTraceId;
            $previousStage = is_array($context) ? $context['previousStage'] : $this->currentStage;

            $this->currentRequestId = $requestId;
            $this->currentParentRequestId = $parentRequestId;
            $this->currentTraceId = $traceId;
            $this->currentStage = 'action';

            try {
                [$started, $finished] = $this->envelopes->nativeEvent(
                    $event,
                    $requestId,
                    $traceId,
                    $parentRequestId,
                );

                if (! $startedBeforeDispatch) {
                    $this->forward($started);
                }

                $this->forward($finished);
                $this->flushTelemetryDrops();
            } finally {
                $this->currentRequestId = $previousRequestId;
                $this->currentParentRequestId = $previousParentRequestId;
                $this->currentTraceId = $previousTraceId;
                $this->currentStage = $previousStage;
            }
        });
        self::rememberNativeHook('native-event-did', $componentClass, 'stopObservingNativeEventDispatched', $didObserverId);
    }

    protected function bindRenderErrors(): void
    {
        if (! (bool) config('tesseract-native.telemetry.exceptions', true)) {
            return;
        }

        /** @var class-string $componentClass */
        $componentClass = 'Native\Mobile\Edge\NativeComponent';

        if (! class_exists($componentClass) || ! method_exists($componentClass, 'observeRenderError')) {
            return;
        }

        $errorObserverId = $componentClass::observeRenderError(function (Throwable $exception, string $sourceComponent = ''): void {
            $this->forward($this->envelopes->exception(
                $exception,
                'error',
                $this->currentRequestId,
                $sourceComponent !== '' ? $sourceComponent : null,
                $this->currentTraceId,
                $this->currentParentRequestId,
                'render',
            ));
        });
        self::rememberNativeHook('render-error', $componentClass, 'stopObservingRenderError', $errorObserverId);
    }

    protected static function clearNativeHookSubscriptions(): void
    {
        foreach (self::$nativeHookSubscriptions as $subscription) {
            $class = $subscription['class'];
            $stop = $subscription['stop'];

            if (class_exists($class) && method_exists($class, $stop)) {
                $class::$stop($subscription['id']);
            }
        }

        self::$nativeHookSubscriptions = [];
    }

    /** @param class-string $componentClass */
    protected static function rememberNativeHook(string $key, string $componentClass, string $stopMethod, int $id): void
    {
        self::$nativeHookSubscriptions[$key] = [
            'class' => $componentClass,
            'stop' => $stopMethod,
            'id' => $id,
        ];
    }

    /** @param  array<string, mixed>  $interaction */
    protected function interactionRequestId(array $interaction, int $counter): string
    {
        return 'int-'.$counter.'-'.substr(md5((string) json_encode([
            $interaction['callbackId'] ?? 0,
            $interaction['method'] ?? '',
            $counter,
        ])), 0, 8);
    }

    /** @param  array<string, mixed>  $event */
    protected function nativeEventRequestId(array $event, int $counter): string
    {
        return 'evt-'.$counter.'-'.substr(md5((string) json_encode([
            $event['event'] ?? '',
            $event['method'] ?? '',
            $counter,
        ])), 0, 8);
    }

    public function reportException(Throwable $exception): void
    {
        if (! (bool) config('tesseract-native.telemetry.exceptions', true)) {
            return;
        }

        $this->forward($this->envelopes->exception(
            $exception,
            'error',
            $this->currentRequestId,
            null,
            $this->currentTraceId,
            $this->currentParentRequestId,
            $this->currentStage,
        ));
    }

    protected function recordTelemetryDrop(string $source, string $reason, ?string $sample = null): void
    {
        $drop = $this->telemetryDrops[$source] ?? [
            'total' => 0,
            'reasons' => [],
            'samples' => [],
        ];
        $drop['total']++;
        $drop['reasons'][$reason] = ($drop['reasons'][$reason] ?? 0) + 1;

        if (
            is_string($sample)
            && $sample !== ''
            && count($drop['samples']) < 10
            && ! in_array($sample, $drop['samples'], true)
        ) {
            $drop['samples'][] = Str::limit($sample, 200, '...');
        }

        $this->telemetryDrops[$source] = $drop;
    }

    protected function flushTelemetryDrops(): void
    {
        if ($this->telemetryDrops === []) {
            return;
        }

        $drops = $this->telemetryDrops;
        $this->telemetryDrops = [];

        $this->forward($this->envelopes->telemetryDropped(
            $drops,
            $this->currentRequestId,
            $this->currentTraceId,
            $this->currentParentRequestId,
        ));
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    protected function forward(array $envelope): void
    {
        if ($this->isMuted()) {
            return;
        }

        $this->forwarding = true;

        try {
            $this->agent->ingest([$envelope]);
        } catch (Throwable) {
        } finally {
            $this->forwarding = false;
        }
    }

    protected function isMuted(): bool
    {
        return $this->forwarding || self::$suppressed;
    }
}
