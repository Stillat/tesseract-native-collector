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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Surfaces the current screen's native component/UI tree from the freshest
 * source the plumbing supports today: the forwarded native screen activity in
 * desktop history (screen navigations carry the landing component + its scope;
 * interactions/lifecycle carry the tree deltas around them).
 *
 * This is a HISTORY read over the desktop loopback, not a live pull from the
 * device — a live tree pull would need the command channel, which the desktop's
 * MCP surface does not expose to external clients. It maps onto the shared
 * `search` history operation with `kind: requests` so it inherits project
 * scoping, byte-bounding, and truncation hints from the desktop.
 */
#[Name('tesseract-view-tree')]
#[Title('Tesseract Native View Tree')]
#[Description('Returns the current screen\'s native component/UI tree as the freshest forwarded snapshot in Tesseract history: native screen navigations (each carrying the landing component and its scope) plus the interactions and component lifecycle around them, newest activity last (timeline order). This is a read of captured desktop history, not a live device pull — a live pull needs the command channel, which the MCP surface does not expose. Expand any entry with tesseract-detail (kind: "request") for its full component scope. Empty session returns an empty envelope, never an error.')]
#[IsReadOnly]
class TesseractViewTreeTool extends AbstractNativeTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sessionId' => $schema->string()->description('Optional Tesseract session id. Defaults to the most recent native session for the current project.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25)->description('Max screen-activity entries to return.'),
            'cursor' => $schema->string()->description('Opaque pagination cursor (`nextCursor` from the previous page). Page forward to reach the current screen in a long session.'),
            'startMs' => $schema->number()->min(0)->description('Inclusive lower bound (milliseconds) on the timeline timestamp — narrow to recent activity to focus on the current screen.'),
            'endMs' => $schema->number()->min(0)->description('Inclusive upper bound (milliseconds) on the timeline timestamp.'),
            'view' => $schema->string()->enum(['summary', 'detail'])->default('summary'),
            'maxBytes' => $schema->integer()->min(1024)->max(65536)->default(16384),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'sessionId' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
            'startMs' => ['nullable', 'numeric', 'min:0'],
            'endMs' => ['nullable', 'numeric', 'min:0'],
            'view' => ['nullable', 'in:summary,detail'],
            'maxBytes' => ['nullable', 'integer', 'min:1024', 'max:65536'],
        ]);

        // Native screens/component trees are forwarded as stand-in "requests"
        // (navigations + interactions + lifecycle), so the view tree reads back
        // through the shared search operation with the kind fixed.
        $validated['kind'] = 'requests';

        return $this->call('search', $validated);
    }
}
