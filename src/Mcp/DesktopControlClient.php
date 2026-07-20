<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;

class DesktopControlClient
{
    public function __construct(
        protected DesktopLoopbackResolver $resolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $health = $this->request('GET', '/health', authenticated: false);

        if (($health['available'] ?? true) === false) {
            return $health;
        }

        if (! $this->hasToken()) {
            return $this->unavailable('unpaired', 'Pair with the desktop Agent API using `tesseractctl pair`, or set TESSERACT_AGENT_TOKEN for this MCP process.', [
                'desktop' => $health,
            ]);
        }

        $state = $this->request('GET', '/state');

        return [
            'available' => ($state['available'] ?? true) !== false,
            'paired' => true,
            'desktop' => $health,
            'state' => $state,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function action(string $actionId, array $arguments): array
    {
        if (! $this->hasToken()) {
            return $this->unavailable('unpaired', 'Pair with the desktop Agent API using `tesseractctl pair`, then retry this action.');
        }

        return $this->request('POST', '/actions/'.rawurlencode($actionId), [
            'arguments' => $arguments,
        ]);
    }

    public function reachabilityHint(): string
    {
        return $this->resolver->reachabilityHint();
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, ?array $body = null, bool $authenticated = true): array
    {
        try {
            $pending = Http::acceptJson()
                ->timeout(max(1, (int) ceil($this->timeoutMs() / 1000)))
                ->connectTimeout(max(1, (int) ceil($this->timeoutMs() / 1000)));

            if ($authenticated) {
                $token = $this->token();

                if ($token === null) {
                    return $this->unavailable('unpaired', 'No desktop Agent API token is configured.');
                }

                $pending = $pending->withToken($token);
            }

            $response = $method === 'POST'
                ? $pending->post($this->url($path), $body ?? [])
                : $pending->get($this->url($path));

            return $this->decode($response);
        } catch (ConnectionException) {
            return $this->unavailable('desktop-unavailable', $this->reachabilityHint());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(HttpResponse $response): array
    {
        $payload = $response->json();

        if (is_array($payload)) {
            if (($payload['ok'] ?? null) === false && $response->status() === 403) {
                return $this->unavailable('unauthorized', (string) ($payload['error']['hint'] ?? 'The paired token does not have the required scope.'), [
                    'agentResponse' => $payload,
                ]);
            }

            if (($payload['ok'] ?? null) === false && $response->status() === 401) {
                return $this->unavailable('unauthorized', 'The configured desktop Agent API token was rejected. Pair again with `tesseractctl pair`.', [
                    'agentResponse' => $payload,
                ]);
            }

            return $payload;
        }

        return [
            'available' => false,
            'reason' => 'invalid-response',
            'hint' => 'The desktop Agent API returned a non-JSON response.',
            'status' => $response->status(),
        ];
    }

    protected function url(string $path): string
    {
        return $this->resolver->baseUrl().'/agent/v1/'.ltrim($path, '/');
    }

    protected function hasToken(): bool
    {
        return $this->token() !== null;
    }

    protected function token(): ?string
    {
        $token = trim((string) config('tesseract-native.agent_control.token', ''));

        if ($token !== '') {
            return $token;
        }

        $shared = $this->sharedAgentConfig();
        $sharedToken = $shared['token'] ?? null;

        return is_string($sharedToken) && trim($sharedToken) !== ''
            ? trim($sharedToken)
            : null;
    }

    protected function timeoutMs(): int
    {
        return max(250, (int) config('tesseract-native.agent_control.timeout_ms', 1000));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function unavailable(string $reason, string $hint, array $extra = []): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'hint' => $hint,
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sharedAgentConfig(): array
    {
        foreach ($this->sharedAgentConfigPaths() as $path) {
            if (! is_file($path)) {
                continue;
            }

            $payload = json_decode((string) file_get_contents($path), true);

            if (is_array($payload)) {
                return $payload;
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function sharedAgentConfigPaths(): array
    {
        $configured = trim((string) config('tesseract-native.agent_control.config_path', ''));

        if ($configured !== '') {
            return [$configured];
        }

        if (app()->environment('testing')) {
            return [];
        }

        $paths = [];
        $appData = getenv('APPDATA');
        $xdg = getenv('XDG_CONFIG_HOME');
        $home = getenv('HOME') ?: getenv('USERPROFILE');

        if (is_string($appData) && $appData !== '') {
            $paths[] = $appData.DIRECTORY_SEPARATOR.'tesseractctl'.DIRECTORY_SEPARATOR.'config.json';
        }

        if (is_string($xdg) && $xdg !== '') {
            $paths[] = $xdg.DIRECTORY_SEPARATOR.'tesseractctl'.DIRECTORY_SEPARATOR.'config.json';
        }

        if (is_string($home) && $home !== '') {
            $paths[] = $home.DIRECTORY_SEPARATOR.'.config'.DIRECTORY_SEPARATOR.'tesseractctl'.DIRECTORY_SEPARATOR.'config.json';
        }

        return $paths;
    }
}
