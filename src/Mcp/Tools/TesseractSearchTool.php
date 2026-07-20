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

#[Name('tesseract-search')]
#[Title('Tesseract Native Search')]
#[Description('Use this to drill into native history after `tesseract-debug`, or directly when you know which stream you need. Pick `kind: errors|logs|requests|queries|activity|sessions`, optionally narrow with `filters` or `startMs`/`endMs`, and page with `cursor`/`nextCursor`. On native there is no HTTP cycle: `requests` are the stand-in records for screen navigations, dispatched UI interactions (press/toggle), and component mount/unmount — filter the timeline for a screen or a tap that misbehaved. `queries` and `activity` also recover `context.queries.rows` / `context.activity.rows` truncations from a debug response.')]
#[IsReadOnly]
class TesseractSearchTool extends AbstractNativeTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(['errors', 'logs', 'requests', 'queries', 'activity', 'sessions'])->required()->description('Which history stream to query. `requests` covers native navigations, interactions, and component lifecycle. `sessions` is project-scoped (sibling native sessions, the pivot for "investigate the last run"). `queries` and `activity` are recovery paths for debug-response truncations. All other kinds are session-scoped.'),
            'sessionId' => $schema->string()->description('Optional Tesseract session id. Defaults to the most recent native session for the current project.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25)->description('Max entries per page.'),
            'cursor' => $schema->string()->description('Opaque pagination cursor returned as `nextCursor` from the previous page.'),
            'startMs' => $schema->number()->min(0)->description('Inclusive lower bound (milliseconds) on the timeline timestamp.'),
            'endMs' => $schema->number()->min(0)->description('Inclusive upper bound (milliseconds) on the timeline timestamp.'),
            'filters' => $schema->object([
                'runtime' => $schema->string()->enum(['all', 'native', 'php'])->description('Errors only.'),
                'severity' => $schema->string()->enum(['all', 'warning', 'error', 'fatal'])->description('Errors only.'),
                'level' => $schema->string()->description('Logs only: log level filter.'),
                'query' => $schema->string()->description('Free-text search across messages, screens, and handlers.'),
                'sources' => $schema->string()->description('Logs only: comma-separated source files to scope to.'),
            ])->description('Per-kind filters. Unrecognised keys for the chosen kind are ignored.'),
            'view' => $schema->string()->enum(['summary', 'detail'])->default('summary'),
            'maxBytes' => $schema->integer()->min(1024)->max(65536)->default(16384),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:errors,logs,requests,queries,activity,sessions'],
            'sessionId' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:512'],
            'startMs' => ['nullable', 'numeric', 'min:0'],
            'endMs' => ['nullable', 'numeric', 'min:0'],
            'filters' => ['nullable', 'array'],
            'filters.runtime' => ['nullable', 'in:all,native,php'],
            'filters.severity' => ['nullable', 'in:all,warning,error,fatal'],
            'filters.level' => ['nullable', 'string', 'max:20'],
            'filters.query' => ['nullable', 'string', 'max:200'],
            'filters.sources' => ['nullable', 'string', 'max:512'],
            'view' => ['nullable', 'in:summary,detail'],
            'maxBytes' => ['nullable', 'integer', 'min:1024', 'max:65536'],
        ]);

        return $this->call('search', $validated);
    }
}
