<?php

declare(strict_types=1);

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeEventHandlers;
use Native\Mobile\Edge\RuntimeObservers;
use Tesseract\NativeCollector\Commands\ReservedNativeEventRegistrar;
use Tesseract\NativeCollector\Instrumentation\ElementInstrumentation;
use Tesseract\NativeCollector\Telemetry\RuntimeHookAdapter;

afterEach(function (): void {
    RuntimeHookAdapter::reset();
    RuntimeObservers::reset();
    ElementInstrumentation::reset();
    NativeElementCollector::reset();
    NativeElementCollector::stopCapturingAttributes();
    NativeElementCollector::stopTransformingAttributes();
    NativeEventHandlers::reset();
});

it('handles namespaced style commands through the generic event registry', function (): void {
    $component = new class extends NativeComponent
    {
        public function render(): Column
        {
            return Column::make();
        }
    };
    $metadata = base64_encode((string) json_encode(['f' => 'resources/views/native/home.blade.php', 'l' => 12]));
    $key = sha1('resources/views/native/home.blade.php:12');

    ElementInstrumentation::register();
    (new ReservedNativeEventRegistrar)->register();
    NativeEventHandlers::dispatch('tesseract:set-style', [
        'nodeId' => 42,
        'key' => $key,
        'classes' => 'p-8',
    ], $component);

    NativeElementCollector::setOwner($component);
    NativeElementCollector::leaf('column', [
        'class' => 'p-2',
        'tesseract-meta' => $metadata,
    ]);
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout']['padding'])->toBe(32.0)
        ->and($tree['props']['_dbg_classes'])->toBe('p-2')
        ->and($tree['props']['_dbg_classes_active'])->toBe('p-8');
});

it('adapts generic runtime dispatches to collector telemetry callbacks', function (): void {
    $started = [];
    $finished = [];
    RuntimeHookAdapter::observeInteractionWillDispatch(function (array $payload) use (&$started): void {
        $started[] = $payload;
    });
    RuntimeHookAdapter::observeInteractionDispatched(function (array $payload) use (&$finished): void {
        $finished[] = $payload;
    });
    RuntimeHookAdapter::boot();

    RuntimeObservers::dispatchStarting([
        'kind' => 'interaction',
        'type' => 3,
        'before' => ['count' => 1],
    ]);
    RuntimeObservers::dispatchFinished([
        'kind' => 'interaction',
        'type' => 3,
        'before' => ['count' => 1],
        'after' => ['count' => 2],
        'error' => new RuntimeException('broken'),
    ]);

    expect($started[0]['eventType'])->toBe(3)
        ->and($started[0]['stateBefore'])->toBe(['count' => 1])
        ->and($finished[0]['stateAfter'])->toBe(['count' => 2])
        ->and($finished[0]['error'])->toMatchArray([
            'class' => RuntimeException::class,
            'message' => 'broken',
        ]);
});

it('owns source metadata and style correlation outside the runtime', function (): void {
    $component = new class extends NativeComponent
    {
        public function render(): Column
        {
            return Column::make();
        }
    };
    $metadata = base64_encode((string) json_encode(['f' => 'resources/views/native/home.blade.php', 'l' => 12]));

    ElementInstrumentation::register();
    NativeElementCollector::setOwner($component);
    NativeElementCollector::leaf('column', [
        'class' => 'p-2',
        'tesseract-meta' => $metadata,
    ]);
    $first = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect(ElementInstrumentation::setStyleOverrideForKey(
        $component::class,
        sha1('resources/views/native/home.blade.php:12'),
        'p-8',
    ))->toBeTrue();

    NativeElementCollector::reset();
    NativeElementCollector::setOwner($component);
    NativeElementCollector::leaf('column', [
        'class' => 'p-2',
        'tesseract-meta' => $metadata,
    ]);
    $second = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($first['props']['_dbg_rt_tesseract'])->toBe($metadata)
        ->and($first['props']['_dbg_classes'])->toBe('p-2')
        ->and($first['props'] ?? [])->not->toHaveKey('_dbg_classes_active')
        ->and($second['props']['_dbg_classes'])->toBe('p-2')
        ->and($second['props']['_dbg_classes_active'])->toBe('p-8')
        ->and($second['layout']['padding'])->toBe(32.0);
});

it('represents an empty active class override without losing the override state', function (): void {
    $component = new class extends NativeComponent
    {
        public function render(): Column
        {
            return Column::make();
        }
    };
    $metadata = base64_encode((string) json_encode(['f' => 'resources/views/native/home.blade.php', 'l' => 12]));
    $key = sha1('resources/views/native/home.blade.php:12');

    ElementInstrumentation::register();
    expect(ElementInstrumentation::setStyleOverrideForKey($component::class, $key, ''))->toBeTrue();

    NativeElementCollector::setOwner($component);
    NativeElementCollector::leaf('column', [
        'class' => 'p-2',
        'tesseract-meta' => $metadata,
    ]);
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['props']['_dbg_classes'])->toBe('p-2')
        ->and($tree['props']['_dbg_classes_active'])->toBe(' ')
        ->and($tree)->not->toHaveKey('layout');
});

it('gives repeated source elements distinct instrumentation keys from native keys', function (): void {
    $metadata = base64_encode((string) json_encode(['f' => 'resources/views/native/list.blade.php', 'l' => 12]));

    ElementInstrumentation::register();
    NativeElementCollector::leaf('column', [
        'native:key' => 'first',
        'tesseract-meta' => $metadata,
    ]);
    NativeElementCollector::leaf('column', [
        'native:key' => 'second',
        'tesseract-meta' => $metadata,
    ]);

    $root = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($root['children'][0]['props']['_dbg_key'])->toBe(sha1('resources/views/native/list.blade.php:12:first'))
        ->and($root['children'][1]['props']['_dbg_key'])->toBe(sha1('resources/views/native/list.blade.php:12:second'))
        ->and($root['children'][0]['props']['_dbg_key'])->not->toBe($root['children'][1]['props']['_dbg_key']);
});
