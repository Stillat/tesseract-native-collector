<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tesseract\NativeCollector\Media\MediaBrowser;

const MEDIA_BROWSER_TEST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function createMediaBrowserDirectoryLink(string $target, string $link): bool
{
    if (@symlink($target, $link)) {
        return true;
    }

    if (! windows_os()) {
        return false;
    }

    @exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($target), $output, $status);

    return $status === 0 && is_dir($link);
}

beforeEach(function (): void {
    $this->baseDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tesseract-media-test-'.uniqid();
    $this->publicRoot = $this->baseDir.DIRECTORY_SEPARATOR.'public';

    File::ensureDirectoryExists($this->publicRoot.DIRECTORY_SEPARATOR.'images');
    File::put(
        $this->publicRoot.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'logo.png',
        base64_decode(MEDIA_BROWSER_TEST_PNG),
    );
    File::put($this->baseDir.DIRECTORY_SEPARATOR.'secret.txt', 'top secret');

    $this->app->usePublicPath($this->publicRoot);
    $this->browser = new MediaBrowser;
});

afterEach(function (): void {
    foreach (['storage', 'leak'] as $linkName) {
        $link = $this->publicRoot.DIRECTORY_SEPARATOR.$linkName;

        if (is_link($link)) {
            windows_os() ? @rmdir($link) : @unlink($link);
        } elseif (windows_os() && is_dir($link)) {
            @rmdir($link);
        }
    }

    File::deleteDirectory($this->baseDir);
});

it('fetches a public asset with the download shape', function (): void {
    $bytes = base64_decode(MEDIA_BROWSER_TEST_PNG);

    $result = $this->browser->fetch(['src' => '/images/logo.png']);

    expect($result['success'])->toBeTrue()
        ->and($result['src'])->toBe('/images/logo.png')
        ->and($result['name'])->toBe('logo.png')
        ->and($result['extension'])->toBe('png')
        ->and($result['mimeType'])->toBe('image/png')
        ->and($result['size'])->toBe(strlen($bytes))
        ->and(base64_decode($result['content']))->toBe($bytes)
        ->and($result['isComplete'])->toBeTrue();
});

it('resolves srcs carrying a query string', function (): void {
    $result = $this->browser->fetch(['src' => '/images/logo.png?v=2']);

    expect($result['success'])->toBeTrue()
        ->and($result['name'])->toBe('logo.png');
});

it('caps the bytes read and reports an incomplete fetch', function (): void {
    $result = $this->browser->fetch(['src' => '/images/logo.png', 'maxBytes' => 4]);

    expect($result['success'])->toBeTrue()
        ->and(strlen(base64_decode($result['content'])))->toBe(4)
        ->and($result['size'])->toBe(strlen(base64_decode(MEDIA_BROWSER_TEST_PNG)))
        ->and($result['isComplete'])->toBeFalse();
});

it('decodes percent-encoded srcs before resolving them', function (): void {
    File::put(
        $this->publicRoot.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'brand mark.png',
        base64_decode(MEDIA_BROWSER_TEST_PNG),
    );

    $result = $this->browser->fetch(['src' => '/images/brand%20mark.png']);

    expect($result['success'])->toBeTrue()
        ->and($result['name'])->toBe('brand mark.png')
        ->and(base64_decode($result['content']))->toBe(base64_decode(MEDIA_BROWSER_TEST_PNG));
});

it('accepts assets resolving through the public storage link', function (): void {
    $storagePath = $this->baseDir.DIRECTORY_SEPARATOR.'storage';
    $diskRoot = $storagePath.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';

    File::ensureDirectoryExists($diskRoot.DIRECTORY_SEPARATOR.'media');
    File::put(
        $diskRoot.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'avatar.png',
        base64_decode(MEDIA_BROWSER_TEST_PNG),
    );

    if (! createMediaBrowserDirectoryLink($diskRoot, $this->publicRoot.DIRECTORY_SEPARATOR.'storage')) {
        $this->markTestSkipped('Filesystem does not permit creating symlinks or junctions.');
    }

    $this->app->useStoragePath($storagePath);

    $result = $this->browser->fetch(['src' => '/storage/media/avatar.png']);

    expect($result['success'])->toBeTrue()
        ->and($result['name'])->toBe('avatar.png')
        ->and($result['mimeType'])->toBe('image/png')
        ->and(base64_decode($result['content']))->toBe(base64_decode(MEDIA_BROWSER_TEST_PNG))
        ->and($result['isComplete'])->toBeTrue();
});

it('refuses symlinked paths landing outside both asset roots', function (): void {
    if (! createMediaBrowserDirectoryLink($this->baseDir, $this->publicRoot.DIRECTORY_SEPARATOR.'leak')) {
        $this->markTestSkipped('Filesystem does not permit creating symlinks or junctions.');
    }

    $result = $this->browser->fetch(['src' => '/leak/secret.txt']);

    expect($result['success'])->toBeFalse()
        ->and($result['reason'])->toBe('Asset path is outside the public directory.')
        ->and($result)->not->toHaveKey('content');
});

it('refuses paths escaping the public directory', function (): void {
    $result = $this->browser->fetch(['src' => '/../secret.txt']);

    expect($result['success'])->toBeFalse()
        ->and($result['reason'])->toBe('Asset path is outside the public directory.')
        ->and($result)->not->toHaveKey('content');
});

it('refuses deep traversal to nonexistent paths', function (): void {
    $result = $this->browser->fetch(['src' => '../../../../etc/passwd']);

    expect($result['success'])->toBeFalse()
        ->and($result)->not->toHaveKey('content');
});

it('reports missing assets without leaking bytes', function (): void {
    $result = $this->browser->fetch(['src' => '/images/missing.png']);

    expect($result['success'])->toBeFalse()
        ->and($result['reason'])->toBe('Asset not found.');
});

it('rejects an empty src', function (): void {
    $result = $this->browser->fetch(['src' => '   ']);

    expect($result['success'])->toBeFalse()
        ->and($result['reason'])->toBe('No src provided.');
});
