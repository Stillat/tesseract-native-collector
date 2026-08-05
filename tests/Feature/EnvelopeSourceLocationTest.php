<?php

declare(strict_types=1);

use Tesseract\NativeCollector\Telemetry\EnvelopeFactory;

it('maps compiled blade view paths and device paths to project-relative sources in exception envelopes', function (): void {
    $compiledDir = sys_get_temp_dir().'/tesseract-envelope-test/storage/framework/views';

    if (! is_dir($compiledDir)) {
        mkdir($compiledDir, 0755, true);
    }

    $compiledPath = $compiledDir.'/abc123def456.php';
    $originalPath = base_path('resources/views/native/demo.blade.php');

    file_put_contents(
        $compiledPath,
        "<?php throw new RuntimeException('view exploded'); /**PATH {$originalPath} ENDPATH**/",
    );

    try {
        require $compiledPath;

        $this->fail('The compiled fixture should have thrown.');
    } catch (RuntimeException $exception) {
        $envelope = (new EnvelopeFactory)->exception($exception, 'error', 'nav-1-abc12345');
    }

    unlink($compiledPath);

    expect($envelope['payload']['sourcePath'])->toBe('resources/views/native/demo.blade.php')
        ->and($envelope['payload']['sourceLine'])->toBeNull()
        ->and($envelope['payload']['sourceFrame']['file'])->toBe('resources/views/native/demo.blade.php')
        ->and($envelope['payload']['sourceFrame']['line'])->toBeNull()
        ->and($envelope['payload']['frames'][0]['path'])->toBe('resources/views/native/demo.blade.php')
        ->and($envelope['payload']['frames'][0]['line'])->toBeNull()
        ->and($envelope['payload']['frames'][0]['appFrame'])->toBeTrue()
        ->and($envelope['correlation']['requestId'])->toBe('nav-1-abc12345');

    $testFrames = array_values(array_filter(
        $envelope['payload']['frames'],
        static fn (array $frame): bool => str_contains($frame['path'], 'EnvelopeSourceLocationTest.php'),
    ));

    expect($testFrames)->not->toBeEmpty()
        ->and($testFrames[0]['path'])->toBe('tests/Feature/EnvelopeSourceLocationTest.php');
});

it('marks project-relative vendor frames as non-app frames', function (): void {
    $factory = new class extends EnvelopeFactory
    {
        /**
         * @return array{path: string, line: int|null, column: int|null, functionLabel: string, appFrame: bool, language: string, sourceSnippet: string|null}
         */
        public function exposeFrame(string $file, int $line): array
        {
            return $this->frame($file, $line, 'handle', 'Vendor\Package\Job');
        }
    };

    $vendorFrame = $factory->exposeFrame(base_path('vendor/laravel/framework/src/Illuminate/Foundation/Application.php'), 10);
    $appFrame = $factory->exposeFrame(base_path('app/Models/User.php'), 5);

    expect($vendorFrame['path'])->toBe('vendor/laravel/framework/src/Illuminate/Foundation/Application.php')
        ->and($vendorFrame['appFrame'])->toBeFalse()
        ->and($appFrame['path'])->toBe('app/Models/User.php')
        ->and($appFrame['appFrame'])->toBeTrue()
        ->and($appFrame['line'])->toBe(5);
});

it('salvages project-relative suffixes from device sandbox absolute paths', function (): void {
    $factory = new class extends EnvelopeFactory
    {
        /**
         * @return array{path: string, line: int|null, column: int|null, functionLabel: string, appFrame: bool, language: string, sourceSnippet: string|null}
         */
        public function exposeFrame(string $file, int $line): array
        {
            return $this->frame($file, $line, 'render', 'App\\Native\\Home');
        }

        public function exposeRelative(string $path): string
        {
            return $this->projectRelativePath($path);
        }
    };

    $sandboxApp = '/data/user/0/com.example.app/files/app_storage/laravel/app/Native/Home.php';
    $sandboxResources = '/data/user/0/com.example.app/files/app_storage/laravel/resources/views/native/home.blade.php';
    $hostWindowsPath = 'C:/build/checkout/resources/views/native/home.blade.php';

    expect($factory->exposeRelative($sandboxApp))->toBe('app/Native/Home.php')
        ->and($factory->exposeRelative($sandboxResources))->toBe('resources/views/native/home.blade.php')
        ->and($factory->exposeRelative($hostWindowsPath))->toBe('resources/views/native/home.blade.php')
        ->and($factory->exposeFrame($sandboxApp, 42)['path'])->toBe('app/Native/Home.php')
        ->and($factory->exposeFrame($sandboxApp, 42)['line'])->toBe(42);
});
