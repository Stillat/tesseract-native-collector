<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Tesseract\NativeCollector\TesseractNativeCollectorServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders(mixed $app): array
    {
        return [TesseractNativeCollectorServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment(mixed $app): void
    {
        $app['config']->set('tesseract-native.enabled', false);
        $app['config']->set('tesseract-native.mcp.enabled', false);
        $app['config']->set('tesseract-native.boost.enabled', false);
    }
}
