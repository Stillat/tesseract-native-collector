<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Media;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tesseract\NativeCollector\Telemetry\TelemetryForwarder;
use Throwable;

/**
 * Fetches the media assets a native screen references (image `src` props) for
 * the desktop's Layout media panel. A src is either an app-relative asset path
 * (resolved against public_path, contained to the public directory or the
 * storage:link'd public storage disk) or an http(s) URL
 * (fetched with short timeouts under telemetry suppression so the debugger's
 * own fetch never pollutes the capture). Responses mirror the shape
 * StorageBrowser::download returns so the desktop normalizes both identically.
 */
class MediaBrowser
{
    public const DEFAULT_MAX_BYTES = 5242880;

    public const MAX_BYTES = 20971520;

    public const HTTP_CONNECT_TIMEOUT_SECONDS = 3;

    public const HTTP_TOTAL_TIMEOUT_SECONDS = 5;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function fetch(array $payload): array
    {
        $src = is_string($payload['src'] ?? null) ? trim($payload['src']) : '';

        if ($src === '') {
            return ['success' => false, 'reason' => 'No src provided.'];
        }

        $maxBytes = min(max((int) ($payload['maxBytes'] ?? self::DEFAULT_MAX_BYTES), 1), self::MAX_BYTES);

        if (preg_match('#^https?://#i', $src) === 1) {
            return $this->fetchRemote($src, $maxBytes);
        }

        return $this->fetchLocal($src, $maxBytes);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchLocal(string $src, int $maxBytes): array
    {
        $publicRoot = realpath(public_path());

        if ($publicRoot === false) {
            return ['success' => false, 'reason' => 'Public path is unavailable.', 'src' => $src];
        }

        $relative = rawurldecode(ltrim(parse_url($src, PHP_URL_PATH) ?: $src, '/'));
        $resolved = realpath($publicRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));

        if ($resolved === false || ! is_file($resolved)) {
            return ['success' => false, 'reason' => 'Asset not found.', 'src' => $src];
        }

        if (! $this->isContainedInAssetRoot($resolved, $publicRoot)) {
            return ['success' => false, 'reason' => 'Asset path is outside the public directory.', 'src' => $src];
        }

        $size = (int) filesize($resolved);
        $content = (string) file_get_contents($resolved, false, null, 0, $maxBytes);

        return $this->buildResult(
            src: $src,
            name: basename($resolved),
            mimeType: $this->detectMimeType($content, $resolved),
            size: $size,
            content: $content,
        );
    }

    /**
     * A resolved asset must live under the public directory or under the
     * public storage disk root: `storage:link` assets are symlinks whose
     * realpath lands in storage/app/public, outside public_path itself.
     */
    protected function isContainedInAssetRoot(string $resolved, string $publicRoot): bool
    {
        $roots = [$publicRoot];
        $storageRoot = realpath(storage_path('app'.DIRECTORY_SEPARATOR.'public'));

        if ($storageRoot !== false) {
            $roots[] = $storageRoot;
        }

        foreach ($roots as $root) {
            if (str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bounded remote fetch: a Range header asks the server for at most
     * maxBytes up front, and the streamed body is read no further than one
     * byte past the cap either way — a huge (or endless) response can't be
     * buffered wholesale before truncation.
     *
     * @return array<string, mixed>
     */
    protected function fetchRemote(string $src, int $maxBytes): array
    {
        $wasSuppressed = TelemetryForwarder::isSuppressed();
        TelemetryForwarder::suppress();

        try {
            $response = Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::HTTP_TOTAL_TIMEOUT_SECONDS)
                ->withOptions(['stream' => true])
                ->withHeaders(['Range' => 'bytes=0-'.($maxBytes - 1)])
                ->get($src);

            if ($response->failed()) {
                return ['success' => false, 'reason' => "Request failed with status {$response->status()}.", 'src' => $src];
            }

            $body = $this->readStreamedBody($response, $maxBytes + 1);
            $size = $this->declaredRemoteSize($response) ?? strlen($body);
            $content = substr($body, 0, $maxBytes);
            $headerMimeType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

            return $this->buildResult(
                src: $src,
                name: $this->remoteName($src),
                mimeType: $headerMimeType !== '' ? $headerMimeType : $this->detectMimeType($content),
                size: $size,
                content: $content,
            );
        } catch (Throwable $exception) {
            $reason = $exception->getMessage() !== '' ? $exception->getMessage() : 'Media fetch failed.';

            return ['success' => false, 'reason' => $reason, 'src' => $src];
        } finally {
            if (! $wasSuppressed) {
                TelemetryForwarder::resume();
            }
        }
    }

    /**
     * Read at most $limit bytes from the response's PSR-7 stream. One byte
     * beyond maxBytes is enough to know the fetch is incomplete without
     * pulling the rest of the transfer.
     */
    protected function readStreamedBody(Response $response, int $limit): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof() && strlen($body) < $limit) {
            $chunk = $stream->read(min(65536, $limit - strlen($body)));

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return $body;
    }

    /**
     * The full asset size the server declared, when it declared one: the
     * Content-Range total for a 206, the Content-Length otherwise (a 206's
     * Content-Length only covers the returned slice).
     */
    protected function declaredRemoteSize(Response $response): ?int
    {
        if ($response->status() === 206) {
            if (preg_match('#/(\d+)\s*$#', $response->header('Content-Range'), $matches) === 1) {
                return (int) $matches[1];
            }

            return null;
        }

        $length = trim($response->header('Content-Length'));

        return ctype_digit($length) ? (int) $length : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildResult(string $src, string $name, string $mimeType, int $size, string $content): array
    {
        return [
            'success' => true,
            'src' => $src,
            'name' => $name,
            'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'mimeType' => $mimeType,
            'size' => $size,
            'content' => base64_encode($content),
            'isComplete' => strlen($content) >= $size,
        ];
    }

    protected function remoteName(string $src): string
    {
        $path = parse_url($src, PHP_URL_PATH);
        $name = is_string($path) ? basename($path) : '';

        return $name !== '' && $name !== '/' ? $name : 'media';
    }

    protected function detectMimeType(string $content, ?string $path = null): string
    {
        try {
            $info = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $path !== null ? $info->file($path) : $info->buffer($content);

            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        } catch (Throwable) {
            //
        }

        return 'application/octet-stream';
    }
}
