<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Facades;

use Illuminate\Support\Facades\Facade;
use Tesseract\NativeCollector\NativeAgent;

/**
 * @method static bool isAvailable()
 * @method static bool connect(array $config)
 * @method static bool ingest(array $envelopes)
 * @method static array|null status()
 * @method static array takeCommands()
 * @method static void respond(string $commandId, ?string $kind, string $status, ?array $detail)
 *
 * @see NativeAgent
 */
class Tesseract extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NativeAgent::class;
    }
}
