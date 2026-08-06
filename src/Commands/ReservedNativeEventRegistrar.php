<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Commands;

use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeEventHandlers;
use Native\Mobile\Edge\NativeEventHandling;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tesseract\NativeCollector\Instrumentation\ElementInstrumentation;
use Tesseract\NativeCollector\NativeAgent;
use Throwable;

final class ReservedNativeEventRegistrar
{
    /** @var list<int> */
    private static array $handlerIds = [];

    public function __construct(private readonly NativeAgent $agent) {}

    public function register(): void
    {
        $this->unregisterPreviousHandlers();

        self::$handlerIds = [
            NativeEventHandlers::register('tesseract:navigate', $this->navigate(...)),
            NativeEventHandlers::register('tesseract:set-scope', $this->setScope(...)),
            NativeEventHandlers::register('tesseract:call', $this->call(...)),
            NativeEventHandlers::register('tesseract:set-style', $this->setStyle(...)),
        ];
    }

    private function unregisterPreviousHandlers(): void
    {
        foreach (self::$handlerIds as $handlerId) {
            NativeEventHandlers::unregister($handlerId);
        }

        self::$handlerIds = [];
    }

    /** @param array<string, mixed> $payload */
    private function navigate(array $payload, NativeComponent $component): NativeEventHandling
    {
        $uri = $payload['uri'] ?? null;

        if (is_string($uri) && $uri !== '') {
            $component->navigate($uri);
        }

        $this->completeCommand($payload, 'native.navigate', 'ok', ['uri' => $uri]);

        return NativeEventHandling::Handled;
    }

    /** @param array<string, mixed> $payload */
    private function setScope(array $payload, NativeComponent $component): NativeEventHandling
    {
        $property = $payload['property'] ?? null;

        if (! is_string($property) || $property === '') {
            $this->completeCommand($payload, 'native.set-scope', 'error', ['message' => 'No property was provided.']);

            return NativeEventHandling::Handled;
        }

        $propertyReflection = $this->publicComponentProperty($component, $property);

        if (! $propertyReflection instanceof ReflectionProperty) {
            $this->completeCommand($payload, 'native.set-scope', 'error', ['message' => 'The property is not public.']);
            $this->renderError(
                $component,
                new RuntimeException('Cannot mirror set-scope for non-public property [$'.$property.'] on '.$component::class.'.')
            );

            return NativeEventHandling::Handled;
        }

        $value = $this->coercePropertyValue(
            $payload['value'] ?? null,
            $propertyReflection->isInitialized($component)
                ? $propertyReflection->getValue($component)
                : null,
        );

        try {
            $component->__syncProperty($property, $value);
            $this->completeCommand($payload, 'native.set-scope', 'ok', ['property' => $property]);
        } catch (Throwable $exception) {
            $this->completeCommand($payload, 'native.set-scope', 'error', ['message' => $exception->getMessage()]);
            $this->renderError($component, $exception);
        }

        return NativeEventHandling::Handled;
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
    private function call(array $payload, NativeComponent $component): NativeEventHandling
    {
        $method = $payload['method'] ?? null;

        if (! is_string($method) || $method === '') {
            $this->completeCommand($payload, 'native.call', 'error', ['message' => 'No method was provided.']);

            return NativeEventHandling::Handled;
        }

        $methodReflection = $this->publicComponentMethod($component, $method);

        if (! $methodReflection instanceof ReflectionMethod) {
            $this->completeCommand($payload, 'native.call', 'error', ['message' => 'The method is not a public instance method.']);
            $this->renderError(
                $component,
                new RuntimeException('Cannot mirror call ['.$method.'] on '.$component::class.' because it is not a public instance method.')
            );

            return NativeEventHandling::Handled;
        }

        $arguments = is_array($payload['args'] ?? null)
            ? array_values($payload['args'])
            : [];

        try {
            $methodReflection->invokeArgs($component, $arguments);
            $this->completeCommand($payload, 'native.call', 'ok', ['method' => $method]);
        } catch (Throwable $exception) {
            $this->completeCommand($payload, 'native.call', 'error', ['message' => $exception->getMessage()]);
            $this->renderError($component, $exception);
        }

        return NativeEventHandling::Handled;
    }

    /** @param array<string, mixed> $payload */
    private function setStyle(array $payload, NativeComponent $component): NativeEventHandling
    {
        if (($payload['reset'] ?? false) === true) {
            ElementInstrumentation::resetStyleOverrides();
            $this->completeCommand($payload, 'native.set-style', 'ok', ['reset' => true]);

            return NativeEventHandling::Handled;
        }

        $nodeId = $this->nodeId($payload['nodeId'] ?? null);

        $key = is_string($payload['key'] ?? null) ? $payload['key'] : '';

        if ($nodeId === null || $key === '') {
            $this->completeCommand($payload, 'native.set-style', 'error', ['message' => 'The style target is invalid.']);

            return NativeEventHandling::Handled;
        }

        $screen = $component::class;
        $classes = $payload['classes'] ?? null;

        if (is_string($classes)) {
            ElementInstrumentation::setStyleOverrideForKey($screen, $key, $classes);
            $this->completeCommand($payload, 'native.set-style', 'ok', ['key' => $key]);

            return NativeEventHandling::Handled;
        }

        ElementInstrumentation::removeStyleOverrideForKey($screen, $key);
        $this->completeCommand($payload, 'native.set-style', 'ok', ['key' => $key]);

        return NativeEventHandling::Handled;
    }

    private function nodeId(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $detail
     */
    private function completeCommand(array $payload, string $kind, string $status, array $detail): void
    {
        $commandId = $payload['_tesseractCommandId'] ?? null;

        if (! is_string($commandId) || $commandId === '') {
            return;
        }

        $this->agent->respond($commandId, $kind, $status, $detail);
    }
}
