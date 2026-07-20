<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Commands;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Inspector\ElementInspector;
use Native\Mobile\Edge\NativeComponent;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class ReservedNativeEventRegistrar
{
    /** @var list<int> */
    private static array $handlerIds = [];

    public function register(): void
    {
        if (! method_exists(NativeComponent::class, 'registerReservedNativeEventHandler')) {
            return;
        }

        $this->unregisterPreviousHandlers();

        self::$handlerIds = [
            NativeComponent::registerReservedNativeEventHandler('__tesseract:navigate', $this->navigate(...)),
            NativeComponent::registerReservedNativeEventHandler('__tesseract:set-scope', $this->setScope(...)),
            NativeComponent::registerReservedNativeEventHandler('__tesseract:call', $this->call(...)),
            NativeComponent::registerReservedNativeEventHandler('__tesseract:set-style', $this->setStyle(...)),
        ];
    }

    private function unregisterPreviousHandlers(): void
    {
        if (method_exists(NativeComponent::class, 'unregisterReservedNativeEventHandler')) {
            foreach (self::$handlerIds as $handlerId) {
                NativeComponent::unregisterReservedNativeEventHandler($handlerId);
            }
        }

        self::$handlerIds = [];
    }

    /** @param array<string, mixed> $payload */
    private function navigate(array $payload, NativeComponent $component): void
    {
        $uri = $payload['uri'] ?? null;

        if (is_string($uri) && $uri !== '') {
            $component->navigate($uri);
        }
    }

    /** @param array<string, mixed> $payload */
    private function setScope(array $payload, NativeComponent $component): void
    {
        $property = $payload['property'] ?? null;

        if (! is_string($property) || $property === '') {
            return;
        }

        $propertyReflection = $this->publicComponentProperty($component, $property);

        if (! $propertyReflection instanceof ReflectionProperty) {
            $this->renderError(
                $component,
                new RuntimeException('Cannot mirror set-scope for non-public property [$'.$property.'] on '.$component::class.'.')
            );

            return;
        }

        $value = $this->coercePropertyValue(
            $payload['value'] ?? null,
            $propertyReflection->isInitialized($component)
                ? $propertyReflection->getValue($component)
                : null,
        );

        try {
            $component->__syncProperty($property, $value);
        } catch (Throwable $exception) {
            $this->renderError($component, $exception);
        }
    }

    private function coercePropertyValue(mixed $value, mixed $current): mixed
    {
        return match (true) {
            is_int($current) => (int) $value,
            is_float($current) => (float) $value,
            is_bool($current) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    /** @param array<string, mixed> $payload */
    private function call(array $payload, NativeComponent $component): void
    {
        $method = $payload['method'] ?? null;

        if (! is_string($method) || $method === '') {
            return;
        }

        $methodReflection = $this->publicComponentMethod($component, $method);

        if (! $methodReflection instanceof ReflectionMethod) {
            $this->renderError(
                $component,
                new RuntimeException('Cannot mirror call ['.$method.'] on '.$component::class.' because it is not a public instance method.')
            );

            return;
        }

        $arguments = is_array($payload['args'] ?? null)
            ? array_values($payload['args'])
            : [];

        try {
            $methodReflection->invokeArgs($component, $arguments);
        } catch (Throwable $exception) {
            $this->renderError($component, $exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private function setStyle(array $payload, NativeComponent $component): void
    {
        if (($payload['reset'] ?? false) === true) {
            $this->resetStyleOverrides();

            return;
        }

        $nodeId = $this->nodeId($payload['nodeId'] ?? null);

        if ($nodeId === null) {
            return;
        }

        $screen = method_exists($component, 'elementInspectorScope')
            ? $component->elementInspectorScope()
            : $component::class;
        $classes = $payload['classes'] ?? null;

        if (is_string($classes)) {
            $this->storeStyleOverride($screen, $nodeId, $classes);

            return;
        }

        $this->removeStyleOverride($screen, $nodeId);
    }

    private function nodeId(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function resetStyleOverrides(): void
    {
        if (class_exists(ElementInspector::class)) {
            ElementInspector::resetStyleOverrides();

            return;
        }

        $this->updateLegacyStyleOverrides(static fn (): array => []);
    }

    private function storeStyleOverride(string $screen, int $nodeId, string $classes): void
    {
        if (class_exists(ElementInspector::class)) {
            ElementInspector::setStyleOverride($screen, $nodeId, $classes);

            return;
        }

        $this->updateLegacyStyleOverrides(static function (array $overrides) use ($screen, $nodeId, $classes): array {
            $overrides[$screen][$nodeId] = $classes;

            return $overrides;
        });
    }

    private function removeStyleOverride(string $screen, int $nodeId): void
    {
        if (class_exists(ElementInspector::class)) {
            ElementInspector::removeStyleOverride($screen, $nodeId);

            return;
        }

        $this->updateLegacyStyleOverrides(static function (array $overrides) use ($screen, $nodeId): array {
            unset($overrides[$screen][$nodeId]);

            if (($overrides[$screen] ?? []) === []) {
                unset($overrides[$screen]);
            }

            return $overrides;
        });
    }

    /**
     * @param  callable(array<string, array<int, string>>): array<string, array<int, string>>  $mutator
     */
    private function updateLegacyStyleOverrides(callable $mutator): void
    {
        if (! property_exists(Element::class, 'styleOverrides')) {
            return;
        }

        $property = new ReflectionProperty(Element::class, 'styleOverrides');
        $current = $property->getValue();
        $property->setValue(null, $mutator(is_array($current) ? $current : []));
    }

    private function publicComponentProperty(NativeComponent $component, string $property): ?ReflectionProperty
    {
        try {
            $reflection = new ReflectionClass($component);

            if (! $reflection->hasProperty($property)) {
                return null;
            }

            $candidate = $reflection->getProperty($property);
        } catch (ReflectionException) {
            return null;
        }

        return $candidate->isPublic() && ! $candidate->isStatic()
            ? $candidate
            : null;
    }

    private function publicComponentMethod(NativeComponent $component, string $method): ?ReflectionMethod
    {
        if (str_starts_with($method, '__')) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($component);

            if (! $reflection->hasMethod($method)) {
                return null;
            }

            $candidate = $reflection->getMethod($method);
        } catch (ReflectionException) {
            return null;
        }

        return $candidate->isPublic()
            && ! $candidate->isStatic()
            && ! $candidate->isConstructor()
            && ! $candidate->isDestructor()
                ? $candidate
                : null;
    }

    private function renderError(NativeComponent $component, Throwable $exception): void
    {
        if (method_exists($component, 'renderErrorScreen')) {
            $component->renderErrorScreen($exception);

            return;
        }

        throw $exception;
    }
}
