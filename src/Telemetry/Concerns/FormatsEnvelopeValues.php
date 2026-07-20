<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry\Concerns;

use Illuminate\Support\Str;
use Throwable;

trait FormatsEnvelopeValues
{
    protected function transferDurationMs(mixed $response): float
    {
        try {
            $stats = $response->transferStats ?? null;
            $seconds = $stats !== null && method_exists($stats, 'getTransferTime') ? $stats->getTransferTime() : null;

            return is_numeric($seconds) ? (float) $seconds * 1000 : 0.0;
        } catch (Throwable) {
            return 0.0;
        }
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    protected function flattenHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $values) {
            if (! is_string($name)) {
                continue;
            }

            $flat[$name] = is_array($values) ? implode(', ', array_map('strval', $values)) : (string) $values;
        }

        return $flat;
    }

    protected function normalizedSummaryText(string $content): string
    {
        $decoded = html_entity_decode(
            $content,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $normalized = preg_replace('/\s+/u', ' ', trim($decoded));

        return is_string($normalized) ? $normalized : trim($decoded);
    }

    protected function stringify(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? Str::limit($json, 4000, '...') : '';
    }

    /**
     * @param  array<int|string, mixed>  $context
     * @return array<int|string, mixed>
     */
    protected function safeContext(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $safe[$key] = ['class' => $value::class, 'message' => $value->getMessage()];

                continue;
            }

            $safe[$key] = is_scalar($value) || $value === null ? $value : get_debug_type($value);
        }

        return $safe;
    }

    /** @param array<int|string, mixed> $scope */
    protected function safeScope(array $scope, int $depth = 0): array
    {
        $safe = [];

        foreach ($scope as $key => $value) {
            $safe[$key] = $this->safeScopeValue($value, $depth);
        }

        return $safe;
    }

    protected function safeScopeValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $depth >= 4 ? '['.get_debug_type($value).']' : $this->safeScope($value, $depth + 1);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        return '<'.get_debug_type($value).'>';
    }
}
