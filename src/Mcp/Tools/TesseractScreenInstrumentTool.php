<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Tesseract\NativeCollector\Mcp\DesktopControlClient;

#[Name('tesseract-screen-instrument')]
#[Title('Tesseract Screen Instrument')]
#[Description('Instruments an item from the active live screen tree through the desktop Agent API. Supports native highlight/scroll/style/dispatch operations and web highlight/scroll/class/attribute operations.')]
#[IsReadOnly(false)]
#[IsOpenWorld]
#[IsDestructive(false)]
#[IsIdempotent(false)]
class TesseractScreenInstrumentTool extends Tool
{
    public function __construct(
        protected DesktopControlClient $desktop,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->enum(['highlight', 'clear-highlight', 'scroll-into-view', 'set-style', 'set-classes', 'set-attributes', 'dispatch'])->required(),
            'surface' => $schema->string()->enum(['auto', 'web', 'native'])->default('auto'),
            'query' => $schema->string()->description('Optional target lookup query. Prefer an exact nodeId/path/instrumentationKey/targetNodeId from tesseract-screen-find when available.'),
            'nodeId' => $schema->string()->description('Web/native runtime node id from tesseract-screen-find.'),
            'path' => $schema->string()->description('Tree path from tesseract-screen-find.'),
            'instrumentationKey' => $schema->string()->description('Instrumentation key from tesseract-screen-find.'),
            'targetNodeId' => $schema->integer()->min(0)->description('Native flat-buffer node id from tesseract-screen-find.'),
            'captureId' => $schema->string()->description('Optional live capture id.'),
            'classes' => $schema->string()->description('Native class override for set-style.'),
            'reset' => $schema->boolean()->description('Native set-style reset flag.'),
            'addClasses' => $schema->array()->description('Web classes to add for set-classes.'),
            'removeClasses' => $schema->array()->description('Web classes to remove for set-classes.'),
            'addAttributes' => $schema->object()->description('Web attributes to add for set-attributes.'),
            'removeAttributes' => $schema->array()->description('Web attributes to remove for set-attributes.'),
            'sender' => $schema->string()->enum(['press', 'longpress', 'text', 'submit', 'toggle', 'checkbox', 'slider', 'select'])->description('Native dispatch sender.'),
            'callbackId' => $schema->integer()->min(0)->description('Native callback id for dispatch.'),
            'value' => $schema->string()->description('Optional dispatch value.'),
            'waitForResponse' => $schema->boolean()->default(true),
            'responseTimeoutMs' => $schema->integer()->min(1)->max(10000)->default(1500),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'operation' => ['required', 'in:highlight,clear-highlight,scroll-into-view,set-style,set-classes,set-attributes,dispatch'],
            'surface' => ['nullable', 'in:auto,web,native'],
            'query' => ['nullable', 'string', 'max:500'],
            'nodeId' => ['nullable', 'string', 'max:200'],
            'path' => ['nullable', 'string', 'max:500'],
            'instrumentationKey' => ['nullable', 'string', 'max:200'],
            'targetNodeId' => ['nullable', 'integer', 'min:0'],
            'captureId' => ['nullable', 'string', 'max:120'],
            'scrollIntoView' => ['nullable', 'boolean'],
            'durationMs' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'classes' => ['nullable', 'string', 'max:4000'],
            'reset' => ['nullable', 'boolean'],
            'addClasses' => ['nullable', 'array'],
            'removeClasses' => ['nullable', 'array'],
            'addAttributes' => ['nullable', 'array'],
            'removeAttributes' => ['nullable', 'array'],
            'sender' => ['nullable', 'in:press,longpress,text,submit,toggle,checkbox,slider,select'],
            'callbackId' => ['nullable', 'integer', 'min:0'],
            'value' => ['nullable'],
            'waitForResponse' => ['nullable', 'boolean'],
            'responseTimeoutMs' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        return Response::text(json_encode(
            $this->desktop->action('screen.item.instrument', $validated),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
