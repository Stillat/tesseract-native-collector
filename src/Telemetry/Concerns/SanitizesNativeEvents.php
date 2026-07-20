<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry\Concerns;

use Illuminate\Support\Str;
use Throwable;

trait SanitizesNativeEvents
{
    protected function nativeEventLabel(string $eventName): string
    {
        if ($eventName === '__deeplink') {
            return 'DEEP_LINK';
        }

        return Str::of(class_basename($eventName))
            ->snake()
            ->upper()
            ->toString();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function safeNativeEventPayload(string $eventName, array $payload, ?bool &$truncated = null): array
    {
        return $this->boundedNativeEventValues($eventName, $payload, true, $truncated);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function safeNativeEventState(string $eventName, array $state, ?bool &$truncated = null): array
    {
        return $this->boundedNativeEventValues($eventName, $state, false, $truncated);
    }

    protected function isSensitiveNativeEventPayloadKey(string $eventName, string $key): bool
    {
        if (preg_match('/token|password|secret|authorization|cookie|credential|private.?key/i', $key) === 1) {
            return true;
        }

        return str_contains($eventName, '\\Scanner\\CodeScanned') && strtolower($key) === 'data';
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    protected function boundedNativeEventValues(
        string $eventName,
        array $values,
        bool $stripDeepLinkCredentials,
        ?bool &$truncated = null,
    ): array {
        $remainingEntries = max((int) config('tesseract-native.observability.native_events.max_entries', 100), 0);
        $remainingBytes = max((int) config('tesseract-native.observability.native_events.max_bytes', 32768), 0);
        $maxValueBytes = max((int) config('tesseract-native.observability.native_events.max_value_bytes', 2048), 0);
        $maxDepth = max((int) config('tesseract-native.observability.native_events.max_depth', 4), 0);
        $truncated = false;

        return $this->boundedNativeEventArray(
            $eventName,
            $values,
            $stripDeepLinkCredentials,
            0,
            $maxValueBytes,
            $maxDepth,
            $remainingEntries,
            $remainingBytes,
            $truncated,
        );
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    protected function boundedNativeEventArray(
        string $eventName,
        array $values,
        bool $stripDeepLinkCredentials,
        int $depth,
        int $maxValueBytes,
        int $maxDepth,
        int &$remainingEntries,
        int &$remainingBytes,
        bool &$truncated,
    ): array {
        $safe = [];

        foreach ($values as $key => $value) {
            if ($remainingEntries <= 0 || $remainingBytes <= 0) {
                $truncated = true;

                break;
            }

            $remainingEntries--;
            $label = (string) $key;
            $safeKey = is_int($key)
                ? $key
                : $this->boundedNativeEventString($label, 200, $remainingBytes, $truncated);

            if ($this->isSensitiveNativeEventPayloadKey($eventName, $label)) {
                $safe[$safeKey] = $this->boundedNativeEventString(
                    '[redacted]',
                    10,
                    $remainingBytes,
                    $truncated,
                );

                continue;
            }

            if (
                $stripDeepLinkCredentials
                && $eventName === '__deeplink'
                && in_array(strtolower($label), ['uri', 'url'], true)
                && is_string($value)
            ) {
                $value = preg_split('/[?#]/', $value, 2)[0] ?? '';
            }

            $safe[$safeKey] = $this->boundedNativeEventValue(
                $eventName,
                $value,
                $stripDeepLinkCredentials,
                $depth,
                $maxValueBytes,
                $maxDepth,
                $remainingEntries,
                $remainingBytes,
                $truncated,
            );
        }

        return $safe;
    }

    protected function boundedNativeEventValue(
        string $eventName,
        mixed $value,
        bool $stripDeepLinkCredentials,
        int $depth,
        int $maxValueBytes,
        int $maxDepth,
        int &$remainingEntries,
        int &$remainingBytes,
        bool &$truncated,
    ): mixed {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            $scalar = $this->scalarString($value);

            if (strlen($scalar) > $remainingBytes) {
                return $this->boundedNativeEventString(
                    $scalar,
                    $remainingBytes,
                    $remainingBytes,
                    $truncated,
                );
            }

            $remainingBytes -= strlen($scalar);

            return $value;
        }

        if (is_string($value)) {
            return $this->boundedNativeEventString(
                $value,
                $maxValueBytes,
                $remainingBytes,
                $truncated,
            );
        }

        if (is_array($value)) {
            if ($depth >= $maxDepth) {
                $truncated = true;

                return $this->boundedNativeEventString('[array]', 7, $remainingBytes, $truncated);
            }

            return $this->boundedNativeEventArray(
                $eventName,
                $value,
                $stripDeepLinkCredentials,
                $depth + 1,
                $maxValueBytes,
                $maxDepth,
                $remainingEntries,
                $remainingBytes,
                $truncated,
            );
        }

        if ($value instanceof \BackedEnum) {
            return $this->boundedNativeEventValue(
                $eventName,
                $value->value,
                $stripDeepLinkCredentials,
                $depth,
                $maxValueBytes,
                $maxDepth,
                $remainingEntries,
                $remainingBytes,
                $truncated,
            );
        }

        if ($value instanceof \Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            try {
                $value = (string) $value;
            } catch (Throwable) {
                $value = '<'.$value::class.'>';
            }

            return $this->boundedNativeEventValue(
                $eventName,
                $value,
                $stripDeepLinkCredentials,
                $depth,
                $maxValueBytes,
                $maxDepth,
                $remainingEntries,
                $remainingBytes,
                $truncated,
            );
        }

        return $this->boundedNativeEventString(
            '<'.get_debug_type($value).'>',
            $maxValueBytes,
            $remainingBytes,
            $truncated,
        );
    }

    protected function boundedNativeEventString(
        string $value,
        int $maxBytes,
        int &$remainingBytes,
        bool &$truncated,
    ): string {
        $availableBytes = min(max($maxBytes, 0), max($remainingBytes, 0));
        $valueBytes = strlen($value);

        if ($valueBytes <= $availableBytes) {
            $remainingBytes -= $valueBytes;

            return $value;
        }

        $truncated = true;

        if ($availableBytes === 0) {
            return '';
        }

        $suffix = $availableBytes > 3 ? '...' : '';
        $contentBytes = $availableBytes - strlen($suffix);
        $content = function_exists('mb_strcut')
            ? mb_strcut($value, 0, $contentBytes, 'UTF-8')
            : substr($value, 0, $contentBytes);
        $bounded = $content.$suffix;
        $remainingBytes -= strlen($bounded);

        return $bounded;
    }

    /** @param  array<string, mixed>  $payload */
    protected function nativeEventDetail(array $payload): ?string
    {
        $parts = [];

        foreach ([
            'success',
            'cancelled',
            'action',
            'status',
            'format',
            'mimeType',
            'reason',
            'provider',
            'accuracy',
            'mode',
            'currency',
            'amount',
            'count',
            'error',
            'uri',
            'version',
        ] as $key) {
            if (! array_key_exists($key, $payload) || (! is_scalar($payload[$key]) && $payload[$key] !== null)) {
                continue;
            }

            $parts[] = Str::headline($key).'='.$this->scalarString($payload[$key]);

            if (count($parts) >= 3) {
                break;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    protected function nativeEventArguments(array $payload): array
    {
        $arguments = [];

        foreach ($payload as $key => $value) {
            $arguments[] = (string) $key.'='.(is_scalar($value) || $value === null
                ? $this->scalarString($value)
                : Str::limit($this->stringify($value), 240, '...'));

            if (count($arguments) >= 20) {
                break;
            }
        }

        return $arguments;
    }
}
