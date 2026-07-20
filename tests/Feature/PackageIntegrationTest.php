<?php

declare(strict_types=1);

use Tesseract\NativeCollector\NativeAgent;

it('merges its configuration without starting the native transport', function (): void {
    expect(config('tesseract-native.enabled'))->toBeFalse()
        ->and(app(NativeAgent::class))->toBeInstanceOf(NativeAgent::class);
});

it('degrades safely when the native bridge is unavailable', function (): void {
    $agent = app(NativeAgent::class);

    expect($agent->isAvailable())->toBeFalse()
        ->and($agent->ingest([]))->toBeTrue()
        ->and($agent->status())->toBeNull();
});
