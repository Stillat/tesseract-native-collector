<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;
use Tesseract\NativeCollector\Mcp\Resources\AgentStatusResource;
use Tesseract\NativeCollector\Mcp\Resources\CapabilitiesResource;
use Tesseract\NativeCollector\Mcp\Resources\DesktopStatusResource;
use Tesseract\NativeCollector\Mcp\Tools\TesseractDebugTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractDetailTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractNativeActionTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractProfileSnapshotTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractScreenFindTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractScreenInstrumentTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractScreenshotTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractScreensTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractSearchTool;
use Tesseract\NativeCollector\Mcp\Tools\TesseractViewTreeTool;

#[Name('Tesseract Native')]
#[Version('1.0.0')]
#[Instructions('Read collected Tesseract history for the active NativePHP (mobile) application. Debugging: tesseract-debug for the first call on any "the app broke / red screen / it froze" prompt; tesseract-search for narrow follow-up queries (errors, logs, requests, queries, activity) with cursor pagination. On native, screen navigations and UI interactions are captured as requests; tesseract-detail reads one full record. Inspecting the app: tesseract-screens lists registered jump targets; tesseract-view-tree returns the forwarded component tree from history; tesseract-screenshot captures a live PNG through the paired desktop; tesseract-screen-find returns live target descriptors; tesseract-screen-instrument highlights, scrolls, styles, or dispatches against them. Desktop control tools require a paired desktop Agent API token and advertise read/write annotations.')]
class TesseractNativeServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        TesseractDebugTool::class,
        TesseractSearchTool::class,
        TesseractDetailTool::class,
        TesseractScreensTool::class,
        TesseractViewTreeTool::class,
        TesseractNativeActionTool::class,
        TesseractProfileSnapshotTool::class,
        TesseractScreenshotTool::class,
        TesseractScreenFindTool::class,
        TesseractScreenInstrumentTool::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        AgentStatusResource::class,
        CapabilitiesResource::class,
        DesktopStatusResource::class,
    ];
}
