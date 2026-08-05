<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tesseract\NativeCollector\Blade\NativeViewInstrumenter;

beforeEach(function (): void {
    $this->compiledPath = sys_get_temp_dir().'/tesseract-instrumenter-cache-'.uniqid();
    File::makeDirectory($this->compiledPath, 0755, true);
    config(['view.compiled' => $this->compiledPath]);
});

afterEach(function (): void {
    File::deleteDirectory($this->compiledPath);
});

it('flushes compiled views once when the instrumentation fingerprint changes', function (): void {
    $compiledView = $this->compiledPath.'/abc123.php';
    file_put_contents($compiledView, '<?php echo "stale";');

    // No marker yet — first sync must flush and stamp the fingerprint.
    NativeViewInstrumenter::syncCompiledViewCache();

    $marker = $this->compiledPath.'/.tesseract-native-views';

    expect(is_file($compiledView))->toBeFalse()
        ->and(is_file($marker))->toBeTrue();

    // Same fingerprint — a fresh compile must survive the next sync.
    file_put_contents($compiledView, '<?php echo "current";');

    NativeViewInstrumenter::syncCompiledViewCache();

    expect(is_file($compiledView))->toBeTrue();

    // Fingerprint drift (here: a stale marker) — the cache flushes again.
    file_put_contents($marker, 'different-fingerprint');

    NativeViewInstrumenter::syncCompiledViewCache();

    expect(is_file($compiledView))->toBeFalse()
        ->and(trim((string) file_get_contents($marker)))->not->toBe('different-fingerprint');
});

it('leaves a missing compiled-view directory alone', function (): void {
    config(['view.compiled' => $this->compiledPath.'/does-not-exist']);

    NativeViewInstrumenter::syncCompiledViewCache();

    expect(is_dir($this->compiledPath.'/does-not-exist'))->toBeFalse();
});
