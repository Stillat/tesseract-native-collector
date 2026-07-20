<?php

namespace Tesseract\NativeCollector\Testing;

use Native\Mobile\Edge\Transition;
use Native\Mobile\Testing\TestableComponent;

/**
 * Tesseract reporting proxy — a subclass of the NativePHP {@see TestableComponent}
 * that records what each fluent verb ACTUALLY did (resolved args, per-step
 * timing, the true failing step, dataset rows) as an NDJSON event stream the
 * desktop workbench builds its timeline from.
 *
 * It keeps the vendor core clean: every override calls straight through to
 * `parent::` — the verb/assertion LOGIC lives entirely in the base class and is
 * untouched. The service provider registers this proxy via
 * {@see TestableComponent::useHarness()} when TESSERACT_TEST_REPORT is set,
 * so a normal `artisan test` run never even instantiates it.
 *
 * Because the proxy observes execution from OUR layer (not by editing the
 * vendor's verbs), datasets expand into real rows, computed args are the real
 * values, and control flow is followed — PHP ran it.
 */
class ReportingTestableComponent extends TestableComponent
{
    /** @var list<object> Active reporter spans, including inert nested spans. */
    protected array $activeReportSpans = [];

    protected function __construct(string $componentClass, array $params, array $data, ?string $layout, ?string $platform = null, ?string $uri = null)
    {
        // The mount is the entry step; bracket the whole construction so a mount
        // failure surfaces as the entry failing.
        $span = TestStepReporter::begin(
            'entry',
            $uri !== null ? 'visit' : 'test',
            $uri !== null ? [$uri] : [$componentClass],
        );

        try {
            parent::__construct($componentClass, $params, $data, $layout, $platform, $uri);
        } catch (\Throwable $failure) {
            TestStepReporter::finish($span, 'failed', $failure->getMessage());

            throw $failure;
        }

        TestStepReporter::finish($span, 'passed', null);
    }

    /**
     * Bracket one reported step around a straight `parent::` call. On a PHPUnit
     * assertion failure it records `failed` + the message and rethrows, so the
     * test still fails. Nested harness calls (e.g. press → fireEvent,
     * assertTabActive → assertHasTab) are suppressed by the reporter's depth
     * guard so each test-authored step reports once.
     *
     * @param  list<mixed>  $args
     */
    protected function reportStep(string $phase, string $method, array $args, \Closure $body): mixed
    {
        $span = TestStepReporter::begin($phase, $method, $args, $this->instructionMirror($phase, $method, $args));
        $this->activeReportSpans[] = $span;

        try {
            $result = $body();
        } catch (\Throwable $failure) {
            TestStepReporter::finish($span, 'failed', $failure->getMessage());
            array_pop($this->activeReportSpans);

            throw $failure;
        }

        TestStepReporter::finish($span, 'passed', null);
        array_pop($this->activeReportSpans);

        return $result;
    }

    /**
     * Attach direct component-state instructions whose payload is already known
     * before the parent verb runs. UI events attach later, after the parent
     * resolves the callback id in dispatchUiEvent().
     *
     * @param  list<mixed>  $args
     */
    protected function instructionMirror(string $phase, string $method, array $args): ?array
    {
        if ($phase !== 'instruction') {
            return null;
        }

        if ($method === 'set' && is_string($args[0] ?? null)) {
            return [
                'kind' => 'native.set-scope',
                'payload' => [
                    'property' => $args[0],
                    'value' => $args[1] ?? null,
                ],
            ];
        }

        if ($method === 'call' && is_string($args[0] ?? null)) {
            return [
                'kind' => 'native.call',
                'payload' => [
                    'method' => $args[0],
                    'args' => array_slice($args, 1),
                ],
            ];
        }

        return null;
    }

    protected function dispatchUiEvent(array $event): static
    {
        $type = is_int($event['type'] ?? null) ? $event['type'] : self::EVENT_PRESS;
        $callbackId = is_int($event['callback_id'] ?? null) ? $event['callback_id'] : null;
        $targetNodeId = is_int($event['node_id'] ?? null) ? $event['node_id'] : null;

        $this->attachMirrorCommand([
            'kind' => 'native.dispatch-event',
            'payload' => [
                'sender' => $this->senderForEventType($type),
                'callbackId' => $callbackId,
                'targetNodeId' => $targetNodeId,
                'value' => $this->valueForEvent($event),
            ],
        ]);

        return parent::dispatchUiEvent($event);
    }

    /** @param array<string, mixed> $command */
    protected function attachMirrorCommand(array $command): void
    {
        for ($index = count($this->activeReportSpans) - 1; $index >= 0; $index--) {
            $span = $this->activeReportSpans[$index];

            if (($span->emit ?? false) === true) {
                TestStepReporter::attachMirror($span, $command);

                return;
            }
        }
    }

    protected function senderForEventType(int $type): string
    {
        return match ($type) {
            self::EVENT_LONG_PRESS => 'longpress',
            self::EVENT_TEXT_CHANGE => 'text',
            self::EVENT_TOGGLE_CHANGE => 'toggle',
            self::EVENT_SUBMIT => 'submit',
            self::EVENT_SLIDER_CHANGE => 'slider',
            self::EVENT_CHECKBOX_CHANGE => 'checkbox',
            self::EVENT_RADIO_CHANGE, self::EVENT_SELECT_CHANGE, self::EVENT_TAB_CHANGE => 'select',
            default => 'press',
        };
    }

    /** @param array<string, mixed> $event */
    protected function valueForEvent(array $event): mixed
    {
        if (array_key_exists('text', $event)) {
            return $event['text'];
        }

        if (array_key_exists('value', $event)) {
            return $event['value'];
        }

        return null;
    }

    public function set(string $property, mixed $value): static
    {
        return $this->reportStep('instruction', 'set', [$property, $value], fn () => parent::set($property, $value));
    }

    public function call(string $method, mixed ...$args): static
    {
        return $this->reportStep('instruction', 'call', array_merge([$method], $args), fn () => parent::call($method, ...$args));
    }

    public function tap(string $target): static
    {
        return $this->reportStep('instruction', 'tap', [$target], fn () => parent::tap($target));
    }

    public function press(string $target): static
    {
        return $this->reportStep('instruction', 'press', [$target], fn () => parent::press($target));
    }

    public function longPress(string $target): static
    {
        return $this->reportStep('instruction', 'longPress', [$target], fn () => parent::longPress($target));
    }

    public function input(string $target, string $text): static
    {
        return $this->reportStep('instruction', 'input', [$target, $text], fn () => parent::input($target, $text));
    }

    public function submit(string $target, string $text = ''): static
    {
        return $this->reportStep('instruction', 'submit', [$target, $text], fn () => parent::submit($target, $text));
    }

    public function toggle(string $target, bool $value): static
    {
        return $this->reportStep('instruction', 'toggle', [$target, $value], fn () => parent::toggle($target, $value));
    }

    public function check(string $target, bool $value = true): static
    {
        return $this->reportStep('instruction', 'check', [$target, $value], fn () => parent::check($target, $value));
    }

    public function slide(string $target, float $value): static
    {
        return $this->reportStep('instruction', 'slide', [$target, $value], fn () => parent::slide($target, $value));
    }

    public function selectRadio(string $target, string $value): static
    {
        return $this->reportStep('instruction', 'selectRadio', [$target, $value], fn () => parent::selectRadio($target, $value));
    }

    public function select(string $target, string $value): static
    {
        return $this->reportStep('instruction', 'select', [$target, $value], fn () => parent::select($target, $value));
    }

    public function changeTab(string $target, int $index): static
    {
        return $this->reportStep('instruction', 'changeTab', [$target, $index], fn () => parent::changeTab($target, $index));
    }

    public function dismissSheet(string $target): static
    {
        return $this->reportStep('instruction', 'dismissSheet', [$target], fn () => parent::dismissSheet($target));
    }

    public function fireEvent(string $target, int $type, array $fields = []): static
    {
        return $this->reportStep('instruction', 'fireEvent', [$target, $type, $fields], fn () => parent::fireEvent($target, $type, $fields));
    }

    public function emitNative(string $event, array $payload = []): static
    {
        return $this->reportStep('instruction', 'emitNative', [$event, $payload], fn () => parent::emitNative($event, $payload));
    }

    public function pressBack(): static
    {
        return $this->reportStep('instruction', 'pressBack', [], fn () => parent::pressBack());
    }

    public function firePolls(): static
    {
        return $this->reportStep('instruction', 'firePolls', [], fn () => parent::firePolls());
    }

    public function firePoll(string $method): static
    {
        return $this->reportStep('instruction', 'firePoll', [$method], fn () => parent::firePoll($method));
    }

    public function search(string $query): static
    {
        return $this->reportStep('instruction', 'search', [$query], fn () => parent::search($query));
    }

    public function follow(): static
    {
        return $this->reportStep('flow', 'follow', [], fn () => parent::follow());
    }

    public function followNavigation(): static
    {
        return $this->reportStep('flow', 'followNavigation', [], fn () => parent::followNavigation());
    }

    public function goBack(): TestableComponent
    {
        return $this->reportStep('flow', 'goBack', [], fn () => parent::goBack());
    }

    public function assertScreen(string $componentClass): static
    {
        return $this->reportStep('validation', 'assertScreen', [$componentClass], fn () => parent::assertScreen($componentClass));
    }

    public function assertSee(string $text): static
    {
        return $this->reportStep('validation', 'assertSee', [$text], fn () => parent::assertSee($text));
    }

    public function assertDontSee(string $text): static
    {
        return $this->reportStep('validation', 'assertDontSee', [$text], fn () => parent::assertDontSee($text));
    }

    public function assertSet(string $property, mixed $expected): static
    {
        return $this->reportStep('validation', 'assertSet', [$property, $expected], fn () => parent::assertSet($property, $expected));
    }

    public function assertNotSet(string $property, mixed $value): static
    {
        return $this->reportStep('validation', 'assertNotSet', [$property, $value], fn () => parent::assertNotSet($property, $value));
    }

    public function assertElement(string $type, ?callable $matcher = null): static
    {
        return $this->reportStep('validation', 'assertElement', [$type], fn () => parent::assertElement($type, $matcher));
    }

    public function assertMissingElement(string $type, ?callable $matcher = null): static
    {
        return $this->reportStep('validation', 'assertMissingElement', [$type], fn () => parent::assertMissingElement($type, $matcher));
    }

    public function assertNavigatedTo(string $uri): static
    {
        return $this->reportStep('validation', 'assertNavigatedTo', [$uri], fn () => parent::assertNavigatedTo($uri));
    }

    public function assertReplacedWith(string $uri): static
    {
        return $this->reportStep('validation', 'assertReplacedWith', [$uri], fn () => parent::assertReplacedWith($uri));
    }

    public function assertWentBack(): static
    {
        return $this->reportStep('validation', 'assertWentBack', [], fn () => parent::assertWentBack());
    }

    public function assertExitedToWeb(string $uri): static
    {
        return $this->reportStep('validation', 'assertExitedToWeb', [$uri], fn () => parent::assertExitedToWeb($uri));
    }

    public function assertTransition(Transition|string $transition): static
    {
        $label = $transition instanceof Transition ? $transition->value : $transition;

        return $this->reportStep('validation', 'assertTransition', [$label], fn () => parent::assertTransition($transition));
    }

    public function assertNoNavigation(): static
    {
        return $this->reportStep('validation', 'assertNoNavigation', [], fn () => parent::assertNoNavigation());
    }

    public function assertNativeCalled(string $method, ?callable $paramsFilter = null): static
    {
        return $this->reportStep('validation', 'assertNativeCalled', [$method], fn () => parent::assertNativeCalled($method, $paramsFilter));
    }

    public function assertNativeNotCalled(string $method): static
    {
        return $this->reportStep('validation', 'assertNativeNotCalled', [$method], fn () => parent::assertNativeNotCalled($method));
    }

    public function assertNativeCalledTimes(string $method, int $times): static
    {
        return $this->reportStep('validation', 'assertNativeCalledTimes', [$method, $times], fn () => parent::assertNativeCalledTimes($method, $times));
    }

    public function assertNativeCallOrder(array $methods): static
    {
        return $this->reportStep('validation', 'assertNativeCallOrder', [$methods], fn () => parent::assertNativeCallOrder($methods));
    }

    public function assertAwaitingNativeEvent(string $eventClass): static
    {
        return $this->reportStep('validation', 'assertAwaitingNativeEvent', [$eventClass], fn () => parent::assertAwaitingNativeEvent($eventClass));
    }

    public function assertNotAwaitingNativeEvent(string $eventClass): static
    {
        return $this->reportStep('validation', 'assertNotAwaitingNativeEvent', [$eventClass], fn () => parent::assertNotAwaitingNativeEvent($eventClass));
    }

    public function assertRenderCount(int $count): static
    {
        return $this->reportStep('validation', 'assertRenderCount', [$count], fn () => parent::assertRenderCount($count));
    }

    public function assertRerendered(): static
    {
        return $this->reportStep('validation', 'assertRerendered', [], fn () => parent::assertRerendered());
    }

    public function assertNotRerendered(): static
    {
        return $this->reportStep('validation', 'assertNotRerendered', [], fn () => parent::assertNotRerendered());
    }

    public function assertNavTitle(string $title): static
    {
        return $this->reportStep('validation', 'assertNavTitle', [$title], fn () => parent::assertNavTitle($title));
    }

    public function assertHasTabBar(): static
    {
        return $this->reportStep('validation', 'assertHasTabBar', [], fn () => parent::assertHasTabBar());
    }

    public function assertTabBarHidden(): static
    {
        return $this->reportStep('validation', 'assertTabBarHidden', [], fn () => parent::assertTabBarHidden());
    }

    public function assertTabBarVisible(): static
    {
        return $this->reportStep('validation', 'assertTabBarVisible', [], fn () => parent::assertTabBarVisible());
    }

    public function assertHasTab(string $label): static
    {
        return $this->reportStep('validation', 'assertHasTab', [$label], fn () => parent::assertHasTab($label));
    }

    public function assertTabActive(string $label): static
    {
        return $this->reportStep('validation', 'assertTabActive', [$label], fn () => parent::assertTabActive($label));
    }

    public function assertAccessible(): static
    {
        return $this->reportStep('validation', 'assertAccessible', [], fn () => parent::assertAccessible());
    }

    public function assertMatchesSnapshot(?string $name = null): static
    {
        return $this->reportStep('validation', 'assertMatchesSnapshot', $name !== null ? [$name] : [], fn () => parent::assertMatchesSnapshot($name));
    }

    public function get(string $property): mixed
    {
        return $this->reportStep('inspection', 'get', [$property], fn () => parent::get($property));
    }

    public function tree(): array
    {
        return $this->reportStep('inspection', 'tree', [], fn () => parent::tree());
    }
}
