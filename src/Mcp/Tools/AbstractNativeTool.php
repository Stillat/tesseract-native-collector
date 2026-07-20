<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Mcp\Tools;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Tesseract\NativeCollector\Mcp\McpWorkbenchHistoryClient;
use Tesseract\NativeCollector\Mcp\Support\ProjectIdentity;

/**
 * Slim base for the native Tesseract MCP tools.
 *
 * The desktop does the detailed shaping and truncation. This class attaches the
 * `project` identity to every request and applies a final response byte guard
 * so an oversized desktop payload does not overflow the MCP response.
 */
abstract class AbstractNativeTool extends Tool
{
    public function __construct(
        protected McpWorkbenchHistoryClient $history,
        protected ProjectIdentity $projectIdentity,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function call(string $operation, array $arguments): Response
    {
        $arguments['project'] ??= $this->projectIdentity->projectPayload();

        $result = $this->history->request($operation, $arguments);

        if ($result['accepted'] && is_array($result['payload'])) {
            return Response::text($this->boundedPayload($operation, $arguments, $result['payload']));
        }

        return Response::error($this->messageForReason($result['reason']));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $payload
     */
    protected function boundedPayload(string $operation, array $arguments, array $payload): string
    {
        $encoded = $this->encode($payload);
        $maxBytes = $this->maxBytes($arguments);

        if (strlen($encoded) <= $maxBytes) {
            return $encoded;
        }

        return $this->encode([
            'message' => 'Tesseract Desktop returned more data than this MCP tool response allows.',
            'payloadOmitted' => true,
            'payloadKeys' => array_values(array_filter(array_keys($payload), 'is_string')),
            'meta' => [
                'project' => $arguments['project'] ?? $this->projectIdentity->projectPayload(),
                'truncated' => [[
                    'path' => '*',
                    'reason' => 'mcp-byte-budget',
                    'operation' => $operation,
                    'originalBytes' => strlen($encoded),
                    'maxBytes' => $maxBytes,
                    'next' => [
                        'operation' => $operation,
                        'args' => [
                            'view' => 'summary',
                            'maxBytes' => $maxBytes,
                        ],
                    ],
                ]],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function maxBytes(array $arguments): int
    {
        $maxBytes = is_numeric($arguments['maxBytes'] ?? null)
            ? (int) $arguments['maxBytes']
            : 16384;

        return max(1024, min(65536, $maxBytes));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function messageForReason(?string $reason): string
    {
        return match ($reason) {
            'desktop-unavailable' => 'Tesseract Desktop is not reachable on the loopback. '.$this->history->reachabilityHint(),
            'timeout/network-failure' => 'Tesseract Desktop did not respond in time.',
            'not-found' => 'The requested Tesseract history entry was not found.',
            default => $reason !== null && $reason !== ''
                ? "Tesseract history could not be loaded ({$reason})."
                : 'Tesseract history could not be loaded.',
        };
    }
}
