<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP transport for the native Tesseract MCP history operations
 * (`debug`, `search`, `detail`).
 *
 * The plugin runs on-device, but this client runs on the dev machine (the AI
 * agent invokes it through `php artisan mcp:start`). It reads the native
 * session's captured history back from the Tesseract desktop over the same
 * loopback the collector uses — the desktop aggregates device envelopes over
 * the `adb reverse` tunnel and serves them at `127.0.0.1:<relay_port>`.
 *
 * The read path is loopback-only and unauthenticated by design: the trust
 * boundary is the desktop's `127.0.0.1` bind, and `project: {key, path}` in the
 * body scopes the read. Live capture (the on-device agent) keeps its own
 * session/token model on its own transport.
 */
class McpWorkbenchHistoryClient
{
    public function __construct(
        protected DesktopLoopbackResolver $loopback,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{accepted: bool, status: int|null, reason: string|null, payload: array<string, mixed>|null}
     */
    public function request(string $operation, array $arguments = []): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout($this->timeoutSeconds())
                ->post($this->desktopUrl("/api/transport/mcp/{$operation}"), $arguments);

            $json = $response->json();
            $body = is_array($json) ? $json : null;

            if ($response->successful()) {
                return [
                    'accepted' => true,
                    'status' => $response->status(),
                    'reason' => null,
                    'payload' => $body,
                ];
            }

            return [
                'accepted' => false,
                'status' => $response->status(),
                'reason' => $this->reasonFromResponse($response->status(), $body),
                'payload' => $body,
            ];
        } catch (ConnectionException) {
            return $this->failure('desktop-unavailable');
        } catch (Throwable) {
            return $this->failure('timeout/network-failure');
        }
    }

    /**
     * @return array{accepted: false, status: null, reason: string, payload: null}
     */
    protected function failure(string $reason): array
    {
        return [
            'accepted' => false,
            'status' => null,
            'reason' => $reason,
            'payload' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function reasonFromResponse(int $status, ?array $payload): string
    {
        $reason = $payload['reason'] ?? $payload['error'] ?? null;

        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        return match (true) {
            $status === 404 => 'not-found',
            $status >= 500 => 'desktop-unavailable',
            default => 'upstream-error',
        };
    }

    /**
     * A one-line, actionable hint for a desktop-unavailable failure: the URL the
     * resolution ladder landed on plus the exact preconditions to check.
     */
    public function reachabilityHint(): string
    {
        return $this->loopback->reachabilityHint();
    }

    protected function timeoutSeconds(): float
    {
        $milliseconds = (int) config('tesseract-native.connect_timeout_ms', 3000);

        return max($milliseconds / 1000, 0.25);
    }

    protected function desktopUrl(string $path): string
    {
        return $this->loopback->baseUrl().$path;
    }
}
