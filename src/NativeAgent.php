<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector;

/**
 * PHP-side client for the native Tesseract agent.
 *
 * The agent (Kotlin, running in the shell) owns every socket: it opens the
 * desktop session, broadcasts envelopes, and receives commands. This class is a
 * thin, synchronous bridge over `nativephp_call` — it never touches the network
 * itself. Each method maps one-to-one to a `Tesseract.*` bridge function the
 * plugin registers in `nativephp.json`.
 *
 * Every call is null-safe: when `nativephp_call` is unavailable (running on the
 * dev machine without a device, in tests, or in a non-native runtime) the
 * methods degrade to no-ops so the host app is never disturbed.
 */
class NativeAgent
{
    public function isAvailable(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        if ((bool) config('nativephp-internal.running', false)) {
            return true;
        }

        $platform = strtolower(trim((string) config('nativephp-internal.platform', '')));

        if (in_array($platform, ['android', 'ios'], true)) {
            return true;
        }

        $jumpBridgePort = getenv('JUMP_BRIDGE_PORT');

        return is_string($jumpBridgePort) && trim($jumpBridgePort) !== '';
    }

    /**
     * Start (or re-affirm) the agent transport. Idempotent on the native side.
     *
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): bool
    {
        return $this->call('Tesseract.Connect', $config) !== null;
    }

    /**
     * Hand a batch of envelopes to the agent's broadcast queue. The agent stamps
     * sessionId/seq and ships them over whichever transport is live.
     *
     * @param  array<int, array<string, mixed>>  $envelopes
     */
    public function ingest(array $envelopes): bool
    {
        if ($envelopes === []) {
            return true;
        }

        return $this->call('Tesseract.Ingest', ['envelopes' => $envelopes]) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        return $this->call('Tesseract.Status', []);
    }

    /**
     * Take the host -> target commands the agent has polled, for this process to
     * execute. The agent returns them as a JSON string so nested command
     * payloads survive the bridge.
     *
     * @return array<int, array<string, mixed>>
     */
    public function takeCommands(): array
    {
        $result = $this->call('Tesseract.TakeCommands', []);
        $commands = json_decode((string) ($result['commands'] ?? '[]'), true);

        return is_array($commands) ? $commands : [];
    }

    /**
     * @param  array<string, mixed>|null  $detail
     */
    public function respond(string $commandId, ?string $kind, string $status, ?array $detail): void
    {
        $this->call('Tesseract.Respond', [
            'commandId' => $commandId,
            'kind' => $kind,
            'status' => $status,
            'detail' => $detail,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    protected function call(string $method, array $params): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $result = nativephp_call($method, json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        if (! is_string($result) || $result === '') {
            return null;
        }

        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : null;
    }
}
