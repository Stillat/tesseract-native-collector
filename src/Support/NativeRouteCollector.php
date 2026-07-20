<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Support;

use ReflectionProperty;
use Tesseract\NativeCollector\Telemetry\TelemetryForwarder;
use Throwable;

/**
 * Reads the app's registered native screens from the shell's
 * `Native\Mobile\Edge\NativeRouter::$routes` (protected static) by reflection —
 * the same source {@see TelemetryForwarder::broadcastRoutes()}
 * forwards to the desktop.
 *
 * Routes are registered at app boot, so this is readable on the dev machine
 * wherever the app's Laravel is bootstrapped (including `php artisan mcp:start`),
 * independent of a live device or the desktop loopback. Degrades to an empty
 * list on an un-patched runtime (no shell types), never throwing.
 */
class NativeRouteCollector
{
    /**
     * @var class-string
     */
    protected const ROUTER_CLASS = 'Native\Mobile\Edge\NativeRouter';

    public function available(): bool
    {
        return class_exists(self::ROUTER_CLASS);
    }

    /**
     * @return array<int, array{path: string, name: string, component: string}>
     */
    public function routes(): array
    {
        if (! $this->available()) {
            return [];
        }

        try {
            $property = new ReflectionProperty(self::ROUTER_CLASS, 'routes');
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
}
