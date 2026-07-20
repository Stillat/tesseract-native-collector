<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Commands;

use Tesseract\NativeCollector\Media\MediaBrowser;
use Tesseract\NativeCollector\Sql\SqlExecutor;
use Tesseract\NativeCollector\Storage\StorageBrowser;
use Tesseract\NativeCollector\Tinker\TinkerEvaluator;
use Throwable;

/**
 * Executes host -> target commands in-process and shapes the result the desktop
 * expects.
 *
 * The desktop routes server features (SQL, Tinker, …) as `server-http-proxy`
 * commands carrying `{method, path, body}` — the same contract the WebView
 * runtime fulfils by same-origin fetching the collector. Here there is no HTTP
 * server, so we map the path straight to an in-process handler and return the
 * handler's result as the proxied HTTP response body:
 *
 *   { status: 'success', detail: { status: 200, body: <handler result> } }
 */
class CommandExecutor
{
    public function __construct(
        protected SqlExecutor $sql,
        protected TinkerEvaluator $tinker,
        protected StorageBrowser $storage,
        protected MediaBrowser $media,
    ) {}

    /**
     * @param  array<string, mixed>  $command
     * @return array{status: string, detail: array<string, mixed>|null}
     */
    public function execute(array $command): array
    {
        $kind = is_string($command['kind'] ?? null) ? $command['kind'] : '';
        $payload = is_array($command['payload'] ?? null) ? $command['payload'] : [];

        if ($kind === 'server-http-proxy') {
            return $this->httpProxy($payload);
        }

        if (str_starts_with($kind, 'storage:')) {
            return $this->storage($kind, $payload);
        }

        if (str_starts_with($kind, 'media:')) {
            return $this->media($kind, $payload);
        }

        return ['status' => 'unsupported', 'detail' => null];
    }

    /**
     * Media asset commands. Like storage, the desktop reads the result straight
     * from `detail`.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, detail: array<string, mixed>|null}
     */
    protected function media(string $kind, array $payload): array
    {
        try {
            $body = match ($kind) {
                'media:fetch' => $this->media->fetch($payload),
                default => null,
            };

            if ($body === null) {
                return ['status' => 'unsupported', 'detail' => null];
            }

            return ['status' => 'ok', 'detail' => $body];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'detail' => ['success' => false, 'message' => $exception->getMessage()]];
        }
    }

    /**
     * Storage browser commands. Unlike server-http-proxy, the desktop reads the
     * result straight from `detail` (no nested {status, body}).
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, detail: array<string, mixed>|null}
     */
    protected function storage(string $kind, array $payload): array
    {
        $disk = is_string($payload['disk'] ?? null) ? $payload['disk'] : null;
        $path = is_string($payload['path'] ?? null) ? $payload['path'] : null;

        try {
            $body = match ($kind) {
                'storage:disks' => $this->storage->disks(),
                'storage:list' => $this->storage->list(
                    $disk,
                    (string) ($path ?? ''),
                    isset($payload['fetchMeta']) ? (bool) $payload['fetchMeta'] : null,
                ),
                'storage:read' => $this->storage->read(
                    $disk,
                    $path,
                    (int) ($payload['offset'] ?? 0),
                    (int) ($payload['maxBytes'] ?? StorageBrowser::DEFAULT_MAX_READ_BYTES),
                ),
                'storage:meta' => $this->storage->meta($disk, $path),
                'storage:download' => $this->storage->download(
                    $disk,
                    $path,
                    (int) ($payload['offset'] ?? 0),
                    (int) ($payload['maxBytes'] ?? StorageBrowser::DEFAULT_DOWNLOAD_CHUNK_BYTES),
                ),
                default => null,
            };

            if ($body === null) {
                return ['status' => 'unsupported', 'detail' => null];
            }

            return ['status' => 'ok', 'detail' => $body];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'detail' => ['success' => false, 'message' => $exception->getMessage()]];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, detail: array<string, mixed>|null}
     */
    protected function httpProxy(array $payload): array
    {
        $path = is_string($payload['path'] ?? null) ? $payload['path'] : '';
        $body = is_array($payload['body'] ?? null) ? $payload['body'] : [];

        try {
            $result = $this->dispatch($path, $body);

            if ($result === null) {
                return ['status' => 'unsupported', 'detail' => [
                    'status' => 404,
                    'message' => "No native handler for {$path}.",
                ]];
            }

            return ['status' => 'success', 'detail' => ['status' => 200, 'body' => $result]];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'detail' => [
                'status' => 422,
                'body' => ['message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Command execution failed.'],
            ]];
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    protected function dispatch(string $path, array $body): ?array
    {
        $path = '/'.ltrim($path, '/');

        return match (true) {
            str_starts_with($path, '/sql/execute') => $this->sql->execute(
                sql: (string) ($body['sql'] ?? ''),
                connection: (string) ($body['connection'] ?? config('database.default')),
                sourceQueryId: is_string($body['sourceQueryId'] ?? null) ? $body['sourceQueryId'] : null,
                maxRows: (int) ($body['maxRows'] ?? 200),
            ),
            str_starts_with($path, '/sql/connections') => $this->sql->availableConnections(),
            str_starts_with($path, '/tinker/execute') => $this->tinker->evaluateCode((string) ($body['code'] ?? '')),
            str_starts_with($path, '/tinker/reset') => ['status' => 'reset'],
            default => null,
        };
    }
}
