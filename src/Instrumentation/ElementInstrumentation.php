<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Instrumentation;

use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeElementCollector;

/** Package-owned metadata capture and temporary class overrides. */
final class ElementInstrumentation
{
    /** @var array<class-string, array<string, string>> */
    private static array $styleOverrides = [];

    public static function register(): void
    {
        NativeElementCollector::captureAttribute('tesseract-meta', '_dbg_rt_tesseract');
        NativeElementCollector::captureAttribute('tesseract-key', '_dbg_key');
        NativeElementCollector::captureAttribute('tesseract-class', '_dbg_classes');
        NativeElementCollector::captureAttribute('tesseract-classes-active', '_dbg_classes_active');

        NativeElementCollector::transformAttributes(
            'tesseract.instrumentation',
            static function (string $type, array $attrs, ?NativeComponent $owner): array {
                $meta = self::decodeMetadata($attrs['tesseract-meta'] ?? null);

                if ($meta === null) {
                    return $attrs;
                }

                $key = self::instrumentationKey(
                    $meta,
                    $attrs['native:key'] ?? $attrs['native-key'] ?? null,
                );
                $attrs['tesseract-key'] = $key;
                $attrs['tesseract-class'] = is_string($attrs['class'] ?? null) ? $attrs['class'] : '';

                if ($owner !== null && isset(self::$styleOverrides[$owner::class][$key])) {
                    $activeClasses = self::$styleOverrides[$owner::class][$key];
                    // The runtime's generic capture seam intentionally drops
                    // empty attributes. A whitespace value survives transport
                    // while still tokenizing to an empty effective class list.
                    $attrs['tesseract-classes-active'] = $activeClasses === '' ? ' ' : $activeClasses;
                    $attrs['class'] = $activeClasses;
                }

                return $attrs;
            },
        );
    }

    public static function setStyleOverrideForKey(string $componentClass, string $key, string $classes): bool
    {
        if ($key === '') {
            return false;
        }

        self::$styleOverrides[$componentClass][$key] = $classes;

        return true;
    }

    public static function removeStyleOverrideForKey(string $componentClass, string $key): void
    {
        if ($key !== '') {
            unset(self::$styleOverrides[$componentClass][$key]);
        }
    }

    public static function resetStyleOverrides(): void
    {
        self::$styleOverrides = [];
    }

    public static function reset(): void
    {
        self::$styleOverrides = [];
    }

    private static function decodeMetadata(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);
        $meta = $decoded !== false ? json_decode($decoded, true) : null;

        return is_array($meta) ? $meta : null;
    }

    private static function instrumentationKey(array $meta, mixed $nativeKey = null): string
    {
        $identity = (string) ($meta['f'] ?? '').':'.(string) ($meta['l'] ?? 0);
        $key = match (true) {
            $nativeKey instanceof \BackedEnum => (string) $nativeKey->value,
            $nativeKey instanceof \Stringable => (string) $nativeKey,
            is_scalar($nativeKey) => (string) $nativeKey,
            default => '',
        };

        if ($key !== '') {
            $identity .= ':'.$key;
        }

        return sha1($identity);
    }
}
