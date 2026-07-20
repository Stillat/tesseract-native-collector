<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Mcp;

use Tesseract\NativeCollector\Pairing;

/**
 * Resolves the loopback URL the native MCP history tools dial to reach the
 * Tesseract desktop.
 *
 * When an external harness (Claude Code and friends) spawns the MCP client it
 * injects no env, so the tools must find the desktop the same way the collector's
 * own envelope transport does. The ladder, highest priority first:
 *
 *   1. explicit operator override: `tesseract-native.desktop_loopback_url`
 *      (env `TESSERACT_NATIVE_DESKTOP_LOOPBACK_URL`).
 *   2. the per-launch pairing file the desktop delivered: `relay_url`, else
 *      `host` + `transport_port`. This is exactly what `igniteAgent()` hands the
 *      native agent, so the read path is never less connected than the write path.
 *   3. the configured transport defaults (`transport.host` + `transport.relay_port`).
 *
 * The resolution also carries whether a pairing file was found so a failed
 * request can name what to check (desktop running? project paired?).
 */
class DesktopLoopbackResolver
{
    public function __construct(
        protected Pairing $pairing,
    ) {}

    /**
     * @return array{url: string, source: string, pairingPresent: bool}
     */
    public function resolve(): array
    {
        $pairing = $this->pairing->read();
        $pairingPresent = is_array($pairing) && $pairing !== [];

        $agentConfigured = trim((string) config('tesseract-native.agent_control.base_url', ''));

        if ($agentConfigured !== '') {
            return $this->result($agentConfigured, 'agent-config', $pairingPresent);
        }

        $shared = $this->sharedAgentConfig();
        $sharedBaseUrl = $shared['baseUrl'] ?? null;

        if (is_string($sharedBaseUrl) && trim($sharedBaseUrl) !== '' && $this->agentBaseUrlReachable(trim($sharedBaseUrl))) {
            return $this->result(trim($sharedBaseUrl), 'tesseractctl-config', $pairingPresent);
        }

        $descriptorUrl = $this->descriptorBaseUrl();

        if ($descriptorUrl !== null) {
            return $this->result($descriptorUrl, 'descriptor', $pairingPresent);
        }

        $configured = trim((string) config('tesseract-native.desktop_loopback_url', ''));

        if ($configured !== '') {
            return $this->result($configured, 'config', $pairingPresent);
        }

        if ($pairingPresent) {
            $relayUrl = $pairing['relay_url'] ?? null;

            if (is_string($relayUrl) && trim($relayUrl) !== '') {
                return $this->result(trim($relayUrl), 'pairing', true);
            }

            $host = $pairing['host'] ?? null;
            $port = $pairing['transport_port'] ?? null;

            if (is_string($host) && trim($host) !== '' && is_numeric($port)) {
                return $this->result('http://'.trim($host).':'.(int) $port, 'pairing', true);
            }
        }

        $host = (string) config('tesseract-native.transport.host', '127.0.0.1');
        $port = (int) config('tesseract-native.transport.relay_port', 61230);

        return $this->result('http://'.$host.':'.$port, 'default', $pairingPresent);
    }

    public function baseUrl(): string
    {
        return $this->resolve()['url'];
    }

    /**
     * A one-line, actionable reason a desktop-unavailable failure can carry:
     * the URL that was tried plus the exact preconditions to check.
     */
    public function reachabilityHint(): string
    {
        $resolution = $this->resolve();

        $pairingCheck = $resolution['pairingPresent']
            ? 'a device is paired (.tesseract/pairing.json is present)'
            : 'this project is paired — no .tesseract/pairing.json was found; launch the app from Tesseract Desktop or set TESSERACT_NATIVE_DESKTOP_LOOPBACK_URL';

        return 'Tried '.$resolution['url'].'. Check that Tesseract Desktop is running and that '.$pairingCheck.'.';
    }

    /**
     * @return array{url: string, source: string, pairingPresent: bool}
     */
    protected function result(string $url, string $source, bool $pairingPresent): array
    {
        return [
            'url' => rtrim($url, '/'),
            'source' => $source,
            'pairingPresent' => $pairingPresent,
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

    protected function descriptorBaseUrl(): ?string
    {
        foreach ($this->descriptorPaths() as $path) {
            if (! is_file($path)) {
                continue;
            }

            $payload = json_decode((string) file_get_contents($path), true);
            $baseUrl = is_array($payload) ? ($payload['baseUrl'] ?? null) : null;
            $expiresAt = is_array($payload) ? ($payload['expiresAt'] ?? null) : null;

            if (
                is_string($baseUrl) &&
                trim($baseUrl) !== '' &&
                is_string($expiresAt) &&
                strtotime($expiresAt) !== false &&
                strtotime($expiresAt) > time()
            ) {
                return trim($baseUrl);
            }
        }

        return null;
    }

    protected function agentBaseUrlReachable(string $baseUrl): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'ignore_errors' => true,
                'timeout' => 0.35,
            ],
        ]);

        $payload = @file_get_contents(rtrim($baseUrl, '/').'/agent/v1/health', false, $context);

        if (! is_string($payload) || $payload === '') {
            return false;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) && ($decoded['ok'] ?? null) === true;
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

    /**
     * @return array<int, string>
     */
    protected function descriptorPaths(): array
    {
        $paths = [];
        $explicit = getenv('TESSERACT_AGENT_DESCRIPTOR');
        $appData = getenv('APPDATA');
        $localAppData = getenv('LOCALAPPDATA');
        $home = getenv('HOME') ?: getenv('USERPROFILE');

        if (is_string($explicit) && $explicit !== '') {
            $paths[] = $explicit;
        }

        if (app()->environment('testing')) {
            return $paths;
        }

        foreach (array_filter([$appData, $localAppData]) as $root) {
            foreach (['tesseract-dev', 'Tesseract', 'Tesseract Desktop'] as $name) {
                $paths[] = $root.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'tesseract'.DIRECTORY_SEPARATOR.'agent-control.json';
            }
        }

        if (is_string($home) && $home !== '') {
            $paths[] = $home.DIRECTORY_SEPARATOR.'.tesseract'.DIRECTORY_SEPARATOR.'agent-control.json';
        }

        return $paths;
    }
}
