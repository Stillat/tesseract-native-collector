<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;
use Tesseract\NativeCollector\Mcp\DesktopControlClient;

#[Name('tesseract-native-desktop-status')]
#[Title('Tesseract Desktop Agent Status')]
#[Description('Reports whether the desktop Agent API is reachable and paired for native control actions.')]
#[Uri('tesseract-native://desktop-status')]
#[MimeType('application/json')]
class DesktopStatusResource extends Resource
{
    public function __construct(
        protected DesktopControlClient $desktop,
    ) {}

    public function handle(): Response
    {
        return Response::text(json_encode(
            $this->desktop->status(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
