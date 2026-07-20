<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Tesseract\NativeCollector\Telemetry\Concerns\BuildsFrameworkEventContext;
use Tesseract\NativeCollector\Telemetry\Concerns\FormatsEnvelopeValues;
use Tesseract\NativeCollector\Telemetry\Concerns\ResolvesSourceLocations;
use Tesseract\NativeCollector\Telemetry\Concerns\SanitizesNativeEvents;
use Throwable;

class EnvelopeFactory
{
    use BuildsFrameworkEventContext;
    use FormatsEnvelopeValues;
    use ResolvesSourceLocations;
    use SanitizesNativeEvents;

    protected const MaxMailPreviewLength = 32768;

    /**
     * @return array<string, mixed>
     */
    public function log(
        MessageLogged $event,
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $parentRequestId = null,
        ?string $stage = null,
    ): array {
        return $this->envelope('server.log.recorded', 'log', [
            'requestId' => $requestId,
            'traceId' => $traceId,
            'parentRequestId' => $parentRequestId,
            'level' => $event->level,
            'message' => $event->message,
            'context' => $this->stringify($this->safeContext($event->context)),
            'channel' => (string) config('logging.default', 'app'),
            'timestampMs' => (float) now()->getTimestampMs(),
            'loggedAt' => now()->toISOString(),
            'stage' => $stage,
            'sourceFrame' => $this->resolveSourceFrame(),
            ...$this->memorySnapshot(),
        ], $requestId, $traceId, $parentRequestId);
    }

    /**
     * @return array<string, mixed>
     */
    public function query(
        QueryExecuted $event,
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $parentRequestId = null,
        ?string $stage = null,
    ): array {
        $durationMs = round((float) $event->time, 2);
        $endedAtMs = (float) round(microtime(true) * 1000);
        $startedAtMs = max($endedAtMs - $durationMs, 0.0);

        return $this->envelope('server.query.executed', 'database', [
            'requestId' => $requestId,
            'traceId' => $traceId,
            'parentRequestId' => $parentRequestId,
            'sql' => $event->sql,
            'bindings' => $this->safeContext($event->bindings),
            'timeMs' => $event->time,
            'durationMs' => $durationMs,
            'connection' => $event->connectionName,
            'driver' => $this->queryDriver($event),
            'familyHash' => md5($event->sql),
            'slow' => $durationMs >= 100,
            'startedAtMs' => $startedAtMs,
            'endedAtMs' => $endedAtMs,
            'executedAt' => now()->toISOString(),
            'sourceFrame' => $this->resolveSourceFrame(),
            'stage' => $stage,
            ...$this->memorySnapshot(),
        ], $requestId, $traceId, $parentRequestId);
    }

    /** @return array<string, mixed> */
    public function dump(
        mixed $value,
        ?string $label = null,
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $parentRequestId = null,
        ?string $stage = null,
    ): array {
        $dumper = new CliDumper;
        $dumper->setColors(false);
        $dump = $dumper->dump((new VarCloner)->cloneVar($value), true);
        $summary = $label !== null && trim($label) !== ''
            ? trim($label)
            : Str::limit(trim((string) preg_replace('/\s+/u', ' ', $dump)), 240, '...');

        return $this->envelope('server.dump.recorded', 'dump', [
            'requestId' => $requestId,
            'traceId' => $traceId,
            'parentRequestId' => $parentRequestId,
            'level' => 'debug',
            'channel' => 'dump',
            'message' => $summary !== '' ? $summary : 'Dump',
            'context' => $this->stringify(['value' => $this->safeScopeValue($value, 0)]),
            'dump' => Str::limit($dump, 12000, '...'),
            'label' => $label,
            'timestampMs' => (float) now()->getTimestampMs(),
            'stage' => $stage,
            'sourceFrame' => $this->resolveSourceFrame(),
            ...$this->memorySnapshot(),
        ], $requestId, $traceId, $parentRequestId);
    }

    protected function queryDriver(QueryExecuted $event): ?string
    {
        try {
            return $event->connection->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $payload
     * @param  array<int, string>  $listeners
     * @return array<string, mixed>
     */
    public function frameworkEvent(
        string $eventName,
        array $payload,
        array $listeners = [],
        ?string $requestId = null,
        ?float $cacheDurationMs = null,
        ?string $traceId = null,
        ?string $parentRequestId = null,
        ?string $stage = null,
    ): array {
        return $this->envelope('server.event.dispatched', 'framework', [
            'requestId' => $requestId,
            'traceId' => $traceId,
            'parentRequestId' => $parentRequestId,
            'timestampMs' => $this->timestampMs(),
            'name' => $eventName,
            'label' => class_exists($eventName) ? class_basename($eventName) : $eventName,
            'className' => $this->eventClassName($eventName, $payload),
            'broadcast' => $this->isBroadcastEvent($eventName, $payload[0] ?? null),
            'payloadPreview' => $this->eventPayloadPreview($payload),
            'eventContext' => $this->frameworkEventContext($eventName, $payload, $cacheDurationMs),
            'listeners' => array_values($listeners),
            'category' => $this->eventCategory($eventName),
            'stage' => $stage,
            'sourceFrame' => $this->resolveSourceFrame(),
            ...$this->memorySnapshot(),
        ], $requestId, $traceId, $parentRequestId);
    }

    /**
     * @param  array<int, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function exception(
        Throwable $exception,
        string $level = 'error',
        ?string $requestId = null,
        ?string $componentClass = null,
        ?string $traceId = null,
        ?string $parentRequestId = null,
        ?string $stage = null,
    ): array {
        $rootFrame = $this->frame(
            $exception->getFile(),
            $exception->getLine(),
            null,
            null,
        );
        $sourcePath = $rootFrame['path'];
        $sourceLine = $rootFrame['line'];

        return $this->envelope('server.exception.thrown', 'error', [
            'requestId' => $requestId,
            'traceId' => $traceId,
            'parentRequestId' => $parentRequestId,
            'timestampMs' => (float) round(microtime(true) * 1000),
            'runtime' => 'php',
            'severity' => in_array($level, ['emergency', 'alert', 'critical', 'error'], true) ? 'error' : 'warning',
            'family' => 'exception',
            'typeName' => $exception::class,
            'message' => $exception->getMessage(),
            'handled' => ! in_array($level, ['emergency', 'alert', 'critical', 'error'], true),
            'sourcePath' => $sourcePath,
            'sourceLine' => $sourceLine,
            'sourceColumn' => null,
            'rawTrace' => $this->displayRawTrace($exception),
            'frames' => $this->frames($exception),
            'sourceFrame' => [
                'file' => $sourcePath,
                'line' => $sourceLine,
                'functionName' => null,
            ],
            'componentClass' => $componentClass,
            'stage' => $stage,
            ...$this->memorySnapshot(),
        ], $requestId, $traceId, $parentRequestId);
    }

    /**
     * @return array<string, mixed>
     */
    public function clientResponse(
        ResponseReceived $event,
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $parentRequestId = null,
        ?string $stage = null,
    ): array {
        $url = $event->request->url();
        $endedAtMs = (float) round(microtime(true) * 1000);
        $durationMs = $this->transferDurationMs($event->response);

        return $this->envelope('server.client-request.completed', 'http', [
            'requestId' => $requestId,
            'traceId' => $traceId,
            'parentRequestId' => $parentRequestId,
            'method' => $event->request->method(),
            'url' => $url,
            'serviceLabel' => parse_url($url, PHP_URL_HOST) ?: 'HTTP',
            'statusCode' => $event->response->status(),
            'durationMs' => $durationMs,
            'startedAtMs' => max($endedAtMs - $durationMs, 0.0),
            'endedAtMs' => $endedAtMs,
            'requestHeaders' => $this->flattenHeaders($event->request->headers()),
            'responseHeaders' => $this->flattenHeaders($event->response->headers()),
            'requestPayloadPreview' => ['format' => 'json', 'value' => $this->stringify($event->request->data())],
            'responsePayloadPreview' => $this->clientResponsePayloadPreview($event->response),
            'sourceFrame' => $this->resolveSourceFrame(),
            'stage' => $stage,
            ...$this->memorySnapshot(),
            'meta' => [
                'tags' => [],
                'hostname' => parse_url($url, PHP_URL_HOST) ?: null,
                'traceId' => $traceId,
                'requestId' => $requestId,
            ],
        ], $requestId, $traceId, $parentRequestId);
    }

    /** @return array{format: string, value: string} */
    protected function clientResponsePayloadPreview(mixed $response): array
    {
        try {
            $body = (string) $response->body();
            $contentType = strtolower((string) $response->header('content-type'));
        } catch (Throwable) {
            return ['format' => 'text', 'value' => ''];
        }

        if (str_contains($contentType, 'application/json') || $this->looksLikeJson($body)) {
            return ['format' => 'json', 'value' => Str::limit($body, 4000, '...')];
        }

        $format = str_contains($contentType, 'text/html') || str_starts_with(ltrim($body), '<')
            ? 'html'
            : 'text';

        return ['format' => $format, 'value' => Str::limit($body, 4000, '...')];
    }

    protected function looksLikeJson(string $body): bool
    {
        $trimmed = trim($body);

        if ($trimmed === '' || ! in_array($trimmed[0], ['{', '['], true)) {
            return false;
        }

        json_decode($trimmed);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @return array<string, mixed>
     */
    public function clientFailed(
        ConnectionFailed $event,
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $parentRequestId = null,
        ?string $stage = null,
    ): array {
        $url = $event->request->url();
        $endedAtMs = (float) round(microtime(true) * 1000);

        return $this->envelope('server.client-request.failed', 'http', [
            'requestId' => $requestId,
            'traceId' => $traceId,
            'parentRequestId' => $parentRequestId,
            'method' => $event->request->method(),
            'url' => $url,
            'serviceLabel' => parse_url($url, PHP_URL_HOST) ?: 'HTTP',
            'statusCode' => null,
            'durationMs' => 0.0,
            'startedAtMs' => $endedAtMs,
            'endedAtMs' => $endedAtMs,
            'requestHeaders' => $this->flattenHeaders($event->request->headers()),
            'responseHeaders' => [],
            'requestPayloadPreview' => ['format' => 'json', 'value' => $this->stringify($event->request->data())],
            'responsePayloadPreview' => ['format' => 'text', 'value' => $event->exception->getMessage()],
            'sourceFrame' => $this->resolveSourceFrame(),
            'stage' => $stage,
            ...$this->memorySnapshot(),
            'meta' => [
                'tags' => ['connection-failed'],
                'hostname' => parse_url($url, PHP_URL_HOST) ?: null,
                'traceId' => $traceId,
                'requestId' => $requestId,
            ],
        ], $requestId, $traceId, $parentRequestId);
    }

    /** @return array<string, mixed> */
    public function navigationStarted(
        string $requestId,
        string $uri,
        ?string $fromUri,
        string $componentClass,
        ?string $traceId = null,
        ?string $parentRequestId = null,
    ): array {
        return $this->envelopeWithCorrelation('server.request.started', 'server', $this->correlation(
            $requestId,
            $traceId,
            $parentRequestId,
        ), [
            ...$this->navigationRouteFields($requestId, $uri, $componentClass),
            'query' => $fromUri !== null && $fromUri !== '' ? ['from' => $fromUri] : [],
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $scope
     * @return array<string, mixed>
     */
    public function navigationFinished(
        string $requestId,
        string $uri,
        string $componentClass,
        array $scope,
        float $durationMs,
        ?string $fromUri = null,
        ?string $traceId = null,
        ?string $parentRequestId = null,
    ): array {
        $route = $uri !== '' ? $uri : '/';

        return $this->envelopeWithCorrelation('server.request.finished', 'server', $this->correlation(
            $requestId,
            $traceId,
            $parentRequestId,
        ), [
            ...$this->navigationRouteFields($requestId, $uri, $componentClass),
            'status' => 200,
            'durationMs' => $durationMs,
            'responseEndMs' => $durationMs,
            'contentType' => 'native/view',
            'responseClassification' => 'view',
            'responseBytesKb' => 0.0,
            'responseSummary' => $fromUri !== null && $fromUri !== ''
                ? 'Navigated '.$fromUri.' → '.$route
                : 'Navigated to '.$route,
            'nativeEvent' => [
                'kind' => 'navigation',
                'event' => 'NAVIGATE',
                'component' => $componentClass !== '' ? class_basename($componentClass) : null,
                'screen' => $route,
                'initiator' => $fromUri !== null && $fromUri !== '' ? $fromUri : null,
                'changes' => [],
                'stateAfter' => $this->stateEntries($scope),
            ],
            'responsePayloadPreview' => $this->stringify([
                'route' => $route,
                'from' => $fromUri,
                'component' => $componentClass,
                'scope' => $this->safeScope($scope),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $interaction
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function interaction(
        array $interaction,
        string $requestId,
        ?string $traceId = null,
        ?string $parentRequestId = null,
    ): array {
        $method = is_string($interaction['method'] ?? null) && $interaction['method'] !== '' ? $interaction['method'] : 'handle';
        $class = is_string($interaction['class'] ?? null) ? $interaction['class'] : '';
        $uri = is_string($interaction['uri'] ?? null) ? $interaction['uri'] : '';
        $route = $uri !== '' ? $uri : '/';
        $eventLabel = $this->interactionEventLabel(is_int($interaction['eventType'] ?? null) ? $interaction['eventType'] : -1);
        $before = is_array($interaction['stateBefore'] ?? null) ? $interaction['stateBefore'] : [];
        $after = is_array($interaction['stateAfter'] ?? null) ? $interaction['stateAfter'] : [];
        $durationMs = is_numeric($interaction['durationMs'] ?? null) ? (float) $interaction['durationMs'] : 0.0;
        $args = is_array($interaction['args'] ?? null) ? $interaction['args'] : [];
        $callbackId = is_int($interaction['callbackId'] ?? null) ? $interaction['callbackId'] : 0;
        $nodeId = is_int($interaction['nodeId'] ?? null) ? $interaction['nodeId'] : null;
        $renderCount = is_int($interaction['renderCount'] ?? null) ? $interaction['renderCount'] : null;
        $error = is_array($interaction['error'] ?? null) ? $interaction['error'] : null;
        $changed = $this->scopeDelta($before, $after);

        $routeFields = [
            'requestId' => $requestId,
            'method' => $eventLabel,
            'path' => $route.'#'.$method,
            'route' => $route,
            'url' => 'native://'.ltrim($route, '/').'#'.$method,
            'controllerAction' => $class !== '' ? $class.'@'.$method : $method,
        ];

        $requestHeaders = array_filter([
            'X-Native-Component' => $class,
            'X-Native-Route' => $route,
            'X-Native-Event' => $eventLabel,
            'X-Native-Handler' => $method,
            'X-Native-Callback-Id' => (string) $callbackId,
            'X-Native-Node-Id' => $nodeId !== null ? (string) $nodeId : '',
        ], static fn (string $value): bool => $value !== '');

        $errorMessage = $error !== null && is_string($error['message'] ?? null) ? $error['message'] : null;

        $responseHeaders = array_filter([
            'Content-Type' => 'native/interaction',
            'X-Native-Duration-Ms' => (string) round($durationMs, 3),
            'X-Native-Changed-Keys' => (string) count($changed),
            'X-Native-Render-Count' => $renderCount !== null ? (string) $renderCount : '',
            'X-Native-Error' => $errorMessage ?? '',
        ], static fn (string $value): bool => $value !== '');

        $correlation = $this->correlation($requestId, $traceId, $parentRequestId);
        $started = $this->envelopeWithCorrelation('server.request.started', 'server', $correlation, [
            ...$routeFields,
            'query' => [],
            'requestHeaders' => $requestHeaders,
            'requestPayloadPreview' => $this->stringify([
                'event' => $eventLabel,
                'handler' => $method,
                'arguments' => $args,
                'callbackId' => $callbackId,
                'nodeId' => $nodeId,
            ]),
        ]);

        $summary = $this->interactionSummary($eventLabel, $method, $changed);

        $finished = $this->envelopeWithCorrelation('server.request.finished', 'server', $correlation, [
            ...$routeFields,
            'status' => $error !== null ? 500 : 200,
            'durationMs' => $durationMs,
            'responseEndMs' => $durationMs,
            'contentType' => 'native/interaction',
            'responseClassification' => 'interaction',
            'responseBytesKb' => 0.0,
            'responseHeaders' => $responseHeaders,
            'responseSummary' => $errorMessage !== null ? $summary.' · ERROR: '.$errorMessage : $summary,
            'callbackId' => $callbackId,
            'nodeId' => $nodeId,
            'nativeEvent' => [
                'kind' => 'interaction',
                'event' => $eventLabel,
                'component' => $class !== '' ? class_basename($class) : null,
                'handler' => $method,
                'screen' => $route,
                'initiator' => $route,
                'renderCount' => $renderCount,
                'durationMs' => round($durationMs, 3),
                'changes' => $this->changesList($changed),
                'args' => $this->stringArgs($args),
                'stateAfter' => $this->stateEntries($after),
                'error' => $errorMessage,
            ],
            'responsePayloadPreview' => $this->stringify(array_filter([
                'event' => $eventLabel,
                'component' => $class,
                'method' => $method,
                'renderCount' => $renderCount,
                'args' => $args,
                'changed' => $changed,
                'stateAfter' => $this->safeScope($after),
                'error' => $error,
            ], static fn ($value): bool => $value !== null)),
        ]);

        return [$started, $finished];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function nativeEvent(
        array $event,
        string $requestId,
        ?string $traceId = null,
        ?string $parentRequestId = null,
    ): array {
        $eventName = is_string($event['event'] ?? null) ? $event['event'] : 'native-event';
        $eventLabel = $this->nativeEventLabel($eventName);
        $class = is_string($event['class'] ?? null) ? $event['class'] : '';
        $method = is_string($event['method'] ?? null) && $event['method'] !== ''
            ? $event['method']
            : null;
        $uri = is_string($event['uri'] ?? null) ? $event['uri'] : '';
        $route = $uri !== '' ? $uri : '/';
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $payloadTruncated = false;
        $safePayload = $this->safeNativeEventPayload($eventName, $payload, $payloadTruncated);
        $stateBeforeTruncated = false;
        $before = $this->safeNativeEventState(
            $eventName,
            is_array($event['stateBefore'] ?? null) ? $event['stateBefore'] : [],
            $stateBeforeTruncated,
        );
        $stateAfterTruncated = false;
        $after = $this->safeNativeEventState(
            $eventName,
            is_array($event['stateAfter'] ?? null) ? $event['stateAfter'] : [],
            $stateAfterTruncated,
        );
        $truncated = $payloadTruncated || $stateBeforeTruncated || $stateAfterTruncated;
        $durationMs = is_numeric($event['durationMs'] ?? null) ? (float) $event['durationMs'] : 0.0;
        $renderCount = is_int($event['renderCount'] ?? null) ? $event['renderCount'] : null;
        $error = is_array($event['error'] ?? null) ? $event['error'] : null;
        $errorMessage = is_string($error['message'] ?? null) ? $error['message'] : null;
        $changed = $this->scopeDelta($before, $after);
        $detail = $this->nativeEventDetail($safePayload);
        $handler = $method ?? 'native callback';

        $routeFields = [
            'requestId' => $requestId,
            'method' => $eventLabel,
            'path' => $route.'#'.$eventLabel,
            'route' => $route,
            'url' => 'native://event/'.rawurlencode($eventName),
            'controllerAction' => $class !== '' ? $class.'@'.$handler : $handler,
        ];
        $requestHeaders = array_filter([
            'X-Native-Component' => $class,
            'X-Native-Route' => $route,
            'X-Native-Event' => $eventName,
            'X-Native-Handler' => $method ?? '',
        ], static fn (string $value): bool => $value !== '');
        $responseHeaders = array_filter([
            'Content-Type' => 'native/device-event',
            'X-Native-Duration-Ms' => (string) round($durationMs, 3),
            'X-Native-Changed-Keys' => (string) count($changed),
            'X-Native-Render-Count' => $renderCount !== null ? (string) $renderCount : '',
            'X-Native-Error' => $errorMessage ?? '',
            'X-Native-Truncated' => $truncated ? 'true' : '',
        ], static fn (string $value): bool => $value !== '');
        $correlation = $this->correlation($requestId, $traceId, $parentRequestId);

        $started = $this->envelopeWithCorrelation('server.request.started', 'server', $correlation, [
            ...$routeFields,
            'query' => [],
            'requestHeaders' => $requestHeaders,
            'requestPayloadPreview' => $this->stringify(array_filter([
                'event' => $eventName,
                'handler' => $method,
                'payload' => $safePayload,
                'truncated' => $payloadTruncated ? true : null,
            ], static fn (mixed $value): bool => $value !== null)),
        ]);

        $summary = $eventLabel.($detail !== null ? ' · '.$detail : '');

        $finished = $this->envelopeWithCorrelation('server.request.finished', 'server', $correlation, [
            ...$routeFields,
            'status' => $error !== null ? 500 : 200,
            'durationMs' => $durationMs,
            'responseEndMs' => $durationMs,
            'contentType' => 'native/device-event',
            'responseClassification' => 'interaction',
            'responseBytesKb' => 0.0,
            'responseHeaders' => $responseHeaders,
            'responseSummary' => $errorMessage !== null ? $summary.' · ERROR: '.$errorMessage : $summary,
            'nativeEvent' => [
                'kind' => 'device',
                'event' => $eventLabel,
                'component' => $class !== '' ? class_basename($class) : null,
                'handler' => $method,
                'screen' => $route,
                'initiator' => $route,
                'renderCount' => $renderCount,
                'durationMs' => round($durationMs, 3),
                'changes' => $this->changesList($changed),
                'args' => $this->nativeEventArguments($safePayload),
                'stateAfter' => $this->stateEntries($after),
                'detail' => $detail,
                'error' => $errorMessage,
                'truncated' => $truncated,
            ],
            'responsePayloadPreview' => $this->stringify(array_filter([
                'event' => $eventName,
                'component' => $class,
                'method' => $method,
                'payload' => $safePayload,
                'renderCount' => $renderCount,
                'changed' => $changed,
                'stateAfter' => $this->safeScope($after),
                'error' => $error,
                'truncated' => $truncated ? true : null,
            ], static fn (mixed $value): bool => $value !== null)),
            ...$this->memorySnapshot(),
        ]);

        return [$started, $finished];
    }

    /** @param  array<string, array{from: mixed, to: mixed}>  $changed */
    protected function interactionSummary(string $eventLabel, string $method, array $changed): string
    {
        $head = trim($eventLabel.' '.$method);

        if ($changed === []) {
            return $head;
        }

        $parts = [];
        foreach ($changed as $key => $change) {
            $from = is_scalar($change['from'] ?? null) ? (string) $change['from'] : '…';
            $to = is_scalar($change['to'] ?? null) ? (string) $change['to'] : '…';
            $parts[] = "{$key} {$from} → {$to}";

            if (count($parts) >= 2) {
                break;
            }
        }

        return $head.' · '.implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function componentLifecycle(
        string $phase,
        array $snapshot,
        string $requestId,
        ?string $traceId = null,
        ?string $parentRequestId = null,
    ): array {
        $class = is_string($snapshot['class'] ?? null) ? $snapshot['class'] : '';
        $name = $class !== '' ? class_basename($class) : (is_string($snapshot['name'] ?? null) ? $snapshot['name'] : 'Component');
        $uri = is_string($snapshot['uri'] ?? null) ? $snapshot['uri'] : '';
        $route = $uri !== '' ? $uri : '/';
        $state = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : [];
        $label = strtoupper($phase);

        $routeFields = [
            'requestId' => $requestId,
            'method' => $label,
            'path' => $route.'#'.$phase,
            'route' => $route,
            'url' => 'native://'.ltrim($route, '/').'#'.$phase,
            'controllerAction' => $class !== '' ? $class : $name,
        ];

        $correlation = $this->correlation($requestId, $traceId, $parentRequestId);
        $started = $this->envelopeWithCorrelation('server.request.started', 'server', $correlation, [
            ...$routeFields,
            'query' => [],
            'requestHeaders' => array_filter([
                'X-Native-Component' => $class,
                'X-Native-Route' => $route,
                'X-Native-Lifecycle' => $phase,
            ], static fn (string $value): bool => $value !== ''),
        ]);

        $finished = $this->envelopeWithCorrelation('server.request.finished', 'server', $correlation, [
            ...$routeFields,
            'status' => 200,
            'durationMs' => 0.0,
            'responseEndMs' => 0.0,
            'contentType' => 'native/lifecycle',
            'responseClassification' => 'interaction',
            'responseBytesKb' => 0.0,
            'responseSummary' => $label.' '.$name,
            'nativeEvent' => [
                'kind' => 'lifecycle',
                'event' => $label,
                'component' => $name,
                'screen' => $route,
                'initiator' => $route,
                'changes' => [],
                'stateAfter' => $phase === 'mount' ? $this->stateEntries($state) : [],
            ],
            'responsePayloadPreview' => $this->stringify(array_filter([
                'phase' => $phase,
                'component' => $class,
                'state' => $phase === 'mount' ? $this->safeScope($state) : null,
            ], static fn ($value): bool => $value !== null)),
        ]);

        return [$started, $finished];
    }

    /**
     * @param  array<string, array{total: int, reasons: array<string, int>, samples: array<int, string>}>  $sources
     * @return array<string, mixed>
     */
    public function telemetryDropped(
        array $sources,
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $parentRequestId = null,
    ): array {
        return $this->envelope('collector.telemetry.dropped', 'collector', [
            'requestId' => $requestId,
            'traceId' => $traceId,
            'parentRequestId' => $parentRequestId,
            'total' => array_sum(array_column($sources, 'total')),
            'sources' => $sources,
            'message' => 'Some collector telemetry was intentionally filtered or capped.',
        ], $requestId, $traceId, $parentRequestId);
    }

    protected function interactionEventLabel(int $type): string
    {
        return match ($type) {
            0 => 'PRESS',
            1 => 'LONG_PRESS',
            2 => 'TEXT',
            3 => 'TOGGLE',
            4 => 'SUBMIT',
            8 => 'BACK',
            9 => 'SLIDER',
            10 => 'CHECKBOX',
            11 => 'RADIO',
            12 => 'SELECT',
            13 => 'TAB',
            14 => 'DISMISS',
            default => 'EVENT',
        };
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changed
     * @return array<int, array{key: string, from: string, to: string}>
     */
    protected function changesList(array $changed): array
    {
        $list = [];

        foreach ($changed as $key => $change) {
            $list[] = [
                'key' => (string) $key,
                'from' => $this->scalarString($change['from'] ?? null),
                'to' => $this->scalarString($change['to'] ?? null),
            ];
        }

        return $list;
    }

    /**
     * @param  array<int|string, mixed>  $scope
     * @return array<int, array{key: string, value: string}>
     */
    protected function stateEntries(array $scope): array
    {
        $entries = [];

        foreach ($this->safeScope($scope) as $key => $value) {
            $entries[] = [
                'key' => (string) $key,
                'value' => $this->scalarString($value),
            ];
        }

        return $entries;
    }

    /**
     * @param  array<int, mixed>  $args
     * @return array<int, string>
     */
    protected function stringArgs(array $args): array
    {
        return array_map(fn (mixed $arg): string => $this->scalarString($arg), array_values($args));
    }

    protected function scalarString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? Str::limit($json, 200, '…') : get_debug_type($value);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    protected function scopeDelta(array $before, array $after): array
    {
        $delta = [];

        foreach ($after as $key => $value) {
            $had = array_key_exists($key, $before);
            $prev = $had ? $before[$key] : null;

            if ($had && $prev === $value) {
                continue;
            }

            $delta[$key] = [
                'from' => is_scalar($prev) || $prev === null ? $prev : get_debug_type($prev),
                'to' => is_scalar($value) || $value === null ? $value : get_debug_type($value),
            ];
        }

        return $delta;
    }

    /** @return array<string, mixed> */
    public function componentRemoved(string $componentId): array
    {
        return $this->envelope('native.component.removed', 'component', [
            'framework' => 'native',
            'componentId' => $componentId,
        ]);
    }

    /**
     * @param  array<int, array{path: string, name: string, component: string}>  $routes
     * @return array<string, mixed>
     */
    public function routes(array $routes): array
    {
        return $this->envelope('native.routes.available', 'native', [
            'routes' => array_values($routes),
            'collectedAt' => now()->toISOString(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function navigationRouteFields(string $requestId, string $uri, string $componentClass): array
    {
        $route = $uri !== '' ? $uri : '/';

        return [
            'requestId' => $requestId,
            'method' => 'NAVIGATE',
            'path' => $route,
            'route' => $route,
            'url' => 'native://'.ltrim($route, '/'),
            'controllerAction' => $componentClass !== '' ? $componentClass : null,
        ];
    }

    /**
     * @param  array{id?: mixed, name?: mixed, class?: mixed, uri?: mixed, state?: mixed}  $snapshot
     * @return array<string, mixed>
     */
    public function component(array $snapshot): array
    {
        $state = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : [];

        return $this->envelope('native.component.rendered', 'component', [
            'framework' => 'native',
            'componentId' => is_string($snapshot['id'] ?? null) ? $snapshot['id'] : '',
            'name' => is_string($snapshot['name'] ?? null) ? $snapshot['name'] : 'Component',
            'class' => is_string($snapshot['class'] ?? null) ? $snapshot['class'] : '',
            'uri' => is_string($snapshot['uri'] ?? null) ? $snapshot['uri'] : '',
            'view' => is_string($snapshot['view'] ?? null) ? $snapshot['view'] : null,
            'sourcePath' => $this->relativeProjectPath(is_string($snapshot['viewPath'] ?? null) ? $snapshot['viewPath'] : null),
            'renderCount' => is_int($snapshot['renderCount'] ?? null) ? $snapshot['renderCount'] : null,
            'timings' => is_array($snapshot['timings'] ?? null) ? $snapshot['timings'] : null,
            'state' => $this->safeScope($state),
            'renderedAt' => now()->toISOString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function envelope(
        string $kind,
        string $stream,
        array $payload,
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $parentRequestId = null,
    ): array {
        $correlation = $this->correlation($requestId, $traceId, $parentRequestId);

        if ($correlation !== []) {
            return $this->envelopeWithCorrelation($kind, $stream, $correlation, $payload);
        }

        return [
            'source' => 'php',
            'stream' => $stream,
            'kind' => $kind,
            'sentAt' => now()->toISOString(),
            'payload' => $payload,
        ];
    }

    /** @return array<string, string> */
    protected function correlation(
        ?string $requestId,
        ?string $traceId = null,
        ?string $parentRequestId = null,
    ): array {
        return array_filter([
            'requestId' => $requestId,
            'traceId' => $traceId,
            'parentRequestId' => $parentRequestId,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $correlation
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function envelopeWithCorrelation(string $kind, string $stream, array $correlation, array $payload): array
    {
        return [
            'source' => 'php',
            'stream' => $stream,
            'kind' => $kind,
            'sentAt' => now()->toISOString(),
            'correlation' => $correlation,
            'payload' => $payload,
        ];
    }

    /** @return array{memoryUsageMb: float, memoryPeakMb: float} */
    protected function memorySnapshot(): array
    {
        return [
            'memoryUsageMb' => round(memory_get_usage(true) / 1024 / 1024, 1),
            'memoryPeakMb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ];
    }

    protected function timestampMs(): float
    {
        return (float) round(microtime(true) * 1000);
    }
}
