<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Storage;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Browses the app's configured filesystem disks for the Tesseract desktop
 * storage surface. Mirrors the WebView collector's browser so the desktop
 * normalizes native storage results identically.
 */
class StorageBrowser
{
    public const DEFAULT_MAX_READ_BYTES = 262144;

    public const MAX_READ_BYTES = 1048576;

    public const MAX_IMAGE_BYTES = 5242880;

    public const MAX_PDF_BYTES = 20971520;

    public const DEFAULT_DOWNLOAD_CHUNK_BYTES = 2097152;

    public const MAX_DOWNLOAD_CHUNK_BYTES = 8388608;

    /**
     * @return array<string, mixed>
     */
    public function disks(): array
    {
        $defaultDisk = (string) config('filesystems.default', 'local');
        $connections = [];

        foreach ((array) config('filesystems.disks', []) as $name => $diskConfig) {
            if (! is_array($diskConfig)) {
                continue;
            }

            $connections[] = [
                'id' => (string) $name,
                'driver' => (string) ($diskConfig['driver'] ?? 'unknown'),
                'root' => is_string($diskConfig['root'] ?? null) ? $diskConfig['root'] : null,
                'url' => is_string($diskConfig['url'] ?? null) ? $diskConfig['url'] : null,
                'isDefault' => $name === $defaultDisk,
            ];
        }

        usort(
            $connections,
            static fn (array $left, array $right): int => ($left['isDefault'] === $right['isDefault'])
                ? strcasecmp($left['id'], $right['id'])
                : ($left['isDefault'] ? -1 : 1),
        );

        return [
            'success' => true,
            'defaultDisk' => $defaultDisk,
            'connections' => $connections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function list(?string $disk = null, string $path = '', ?bool $fetchMeta = null): array
    {
        $resolvedDisk = $this->resolveDisk($disk);
        $storage = Storage::disk($resolvedDisk);
        $diskConfig = (array) config("filesystems.disks.{$resolvedDisk}", []);
        $driver = (string) ($diskConfig['driver'] ?? 'local');
        $shouldFetchMeta = $fetchMeta ?? ($driver === 'local');
        $items = [];

        foreach ($storage->directories($path) as $directory) {
            $items[] = [
                'id' => $directory,
                'name' => basename($directory),
                'path' => $directory,
                'type' => 'folder',
            ];
        }

        foreach ($storage->files($path) as $file) {
            $item = [
                'id' => $file,
                'name' => basename($file),
                'path' => $file,
                'type' => 'file',
                'extension' => strtolower(pathinfo($file, PATHINFO_EXTENSION)),
            ];

            if ($shouldFetchMeta) {
                try {
                    $item['size'] = $storage->size($file);
                    $item['lastModified'] = $storage->lastModified($file);
                } catch (Throwable) {
                    //
                }
            }

            $items[] = $item;
        }

        usort(
            $items,
            static fn (array $left, array $right): int => ($left['type'] === $right['type'])
                ? strcasecmp((string) $left['name'], (string) $right['name'])
                : ($left['type'] === 'folder' ? -1 : 1),
        );

        return [
            'disk' => $resolvedDisk,
            'driver' => $driver,
            'path' => $path,
            'items' => $items,
            'metaIncluded' => $shouldFetchMeta,
            'success' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function read(?string $disk = null, ?string $path = null, int $offset = 0, int $maxBytes = self::DEFAULT_MAX_READ_BYTES): array
    {
        if (! is_string($path) || trim($path) === '') {
            return ['success' => false, 'message' => 'No path provided.'];
        }

        $resolvedDisk = $this->resolveDisk($disk);
        $storage = Storage::disk($resolvedDisk);

        if (! $storage->exists($path)) {
            return ['success' => false, 'message' => 'File not found.'];
        }

        $fileSize = $storage->size($path);
        $mimeType = $storage->mimeType($path) ?: 'application/octet-stream';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $clampedOffset = max($offset, 0);
        $clampedMaxBytes = min(max($maxBytes, 1), self::MAX_READ_BYTES);

        if ($this->isImageMimeType($mimeType)) {
            if ($fileSize > self::MAX_IMAGE_BYTES) {
                return ['success' => true, 'type' => 'image', 'tooLarge' => true, 'size' => $fileSize, 'mimeType' => $mimeType, 'path' => $path, 'disk' => $resolvedDisk];
            }

            return ['success' => true, 'type' => 'image', 'content' => base64_encode((string) $storage->get($path)), 'size' => $fileSize, 'mimeType' => $mimeType, 'path' => $path, 'disk' => $resolvedDisk];
        }

        if ($this->isPdfMimeType($mimeType, $extension)) {
            if ($fileSize > self::MAX_PDF_BYTES) {
                return ['success' => true, 'type' => 'pdf', 'tooLarge' => true, 'size' => $fileSize, 'mimeType' => 'application/pdf', 'path' => $path, 'disk' => $resolvedDisk];
            }

            return ['success' => true, 'type' => 'pdf', 'content' => base64_encode((string) $storage->get($path)), 'size' => $fileSize, 'mimeType' => 'application/pdf', 'path' => $path, 'disk' => $resolvedDisk];
        }

        if (! $this->isTextFile($mimeType, $extension)) {
            return ['success' => true, 'type' => 'binary', 'size' => $fileSize, 'mimeType' => $mimeType, 'extension' => $extension, 'path' => $path, 'disk' => $resolvedDisk];
        }

        $stream = $storage->readStream($path);

        if (! is_resource($stream)) {
            $content = (string) $storage->get($path);
            $chunk = substr($content, $clampedOffset, $clampedMaxBytes);
            $nextOffset = $clampedOffset + strlen($chunk);

            return ['success' => true, 'type' => 'text', 'content' => $chunk, 'offset' => $nextOffset, 'size' => $fileSize, 'isComplete' => $nextOffset >= $fileSize, 'mimeType' => $mimeType, 'extension' => $extension, 'path' => $path, 'disk' => $resolvedDisk];
        }

        if ($clampedOffset > 0) {
            fseek($stream, $clampedOffset);
        }

        $content = fread($stream, $clampedMaxBytes);
        $nextOffset = ftell($stream);
        $isComplete = feof($stream);
        fclose($stream);

        if ($content === false) {
            return ['success' => false, 'message' => 'Failed to read the file stream.'];
        }

        return ['success' => true, 'type' => 'text', 'content' => $content, 'offset' => $nextOffset, 'size' => $fileSize, 'isComplete' => $isComplete, 'mimeType' => $mimeType, 'extension' => $extension, 'path' => $path, 'disk' => $resolvedDisk];
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(?string $disk = null, ?string $path = null): array
    {
        if (! is_string($path) || trim($path) === '') {
            return ['success' => false, 'message' => 'No path provided.'];
        }

        $resolvedDisk = $this->resolveDisk($disk);
        $storage = Storage::disk($resolvedDisk);

        if (! $storage->exists($path)) {
            return ['success' => false, 'message' => 'File not found.'];
        }

        $directory = dirname($path);
        $siblingDirectories = $storage->directories($directory === '.' ? '' : $directory);
        $isDirectory = in_array($path, $siblingDirectories, true);

        $meta = [
            'success' => true,
            'path' => $path,
            'disk' => $resolvedDisk,
            'isFile' => ! $isDirectory,
            'isDirectory' => $isDirectory,
            'lastModified' => $storage->lastModified($path),
        ];

        if (! $isDirectory) {
            $meta['size'] = $storage->size($path);
            $meta['mimeType'] = $storage->mimeType($path);
            $meta['visibility'] = $storage->getVisibility($path);

            try {
                $meta['url'] = $storage->url($path);
            } catch (Throwable) {
                $meta['url'] = null;
            }
        }

        return $meta;
    }

    /**
     * @return array<string, mixed>
     */
    public function download(?string $disk = null, ?string $path = null, int $offset = 0, int $maxBytes = self::DEFAULT_DOWNLOAD_CHUNK_BYTES): array
    {
        if (! is_string($path) || trim($path) === '') {
            return ['success' => false, 'message' => 'No path provided.'];
        }

        $resolvedDisk = $this->resolveDisk($disk);
        $storage = Storage::disk($resolvedDisk);

        if (! $storage->exists($path)) {
            return ['success' => false, 'message' => 'File not found.'];
        }

        $fileSize = $storage->size($path);
        $mimeType = $storage->mimeType($path) ?: 'application/octet-stream';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $name = basename($path);
        $clampedOffset = max($offset, 0);
        $clampedMaxBytes = min(max($maxBytes, 1), self::MAX_DOWNLOAD_CHUNK_BYTES);

        $stream = $clampedOffset >= $fileSize ? false : $storage->readStream($path);

        if (! is_resource($stream)) {
            $content = (string) $storage->get($path);
            $chunk = $clampedOffset >= $fileSize ? '' : substr($content, $clampedOffset, $clampedMaxBytes);
            $nextOffset = $clampedOffset + strlen($chunk);

            return $this->buildDownloadChunk($resolvedDisk, $path, $name, $extension, $mimeType, $fileSize, $nextOffset, $chunk);
        }

        if ($clampedOffset > 0) {
            fseek($stream, $clampedOffset);
        }

        $chunk = fread($stream, $clampedMaxBytes);
        $nextOffsetFromStream = ftell($stream);
        fclose($stream);

        if ($chunk === false) {
            return ['success' => false, 'message' => 'Failed to read the file stream.'];
        }

        $nextOffset = is_int($nextOffsetFromStream) ? $nextOffsetFromStream : $clampedOffset + strlen($chunk);

        return $this->buildDownloadChunk($resolvedDisk, $path, $name, $extension, $mimeType, $fileSize, $nextOffset, $chunk);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDownloadChunk(string $disk, string $path, string $name, string $extension, string $mimeType, int $fileSize, int $nextOffset, string $chunk): array
    {
        return [
            'success' => true,
            'disk' => $disk,
            'path' => $path,
            'name' => $name,
            'extension' => $extension,
            'mimeType' => $mimeType,
            'size' => $fileSize,
            'offset' => $nextOffset,
            'content' => base64_encode($chunk),
            'isComplete' => $nextOffset >= $fileSize,
        ];
    }

    protected function resolveDisk(?string $disk): string
    {
        return is_string($disk) && trim($disk) !== '' ? trim($disk) : (string) config('filesystems.default', 'local');
    }

    protected function isImageMimeType(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    protected function isPdfMimeType(string $mimeType, string $extension): bool
    {
        return $mimeType === 'application/pdf' || $extension === 'pdf';
    }

    protected function isTextFile(string $mimeType, string $extension): bool
    {
        return $this->isTextMimeType($mimeType) || $this->isTextExtension($extension);
    }

    protected function isTextMimeType(string $mimeType): bool
    {
        foreach (['text/', 'application/json', 'application/javascript', 'application/xml', 'application/x-httpd-php', 'application/sql', 'application/x-sh', 'application/x-yaml', 'application/xhtml+xml', 'application/x-php', 'application/toml', 'application/ld+json'] as $type) {
            if ($mimeType === $type || str_starts_with($mimeType, $type)) {
                return true;
            }
        }

        return false;
    }

    protected function isTextExtension(string $extension): bool
    {
        return in_array($extension, [
            'bash', 'cfg', 'conf', 'csv', 'env', 'gitignore', 'gradle', 'htaccess', 'ini', 'java', 'json', 'jsonl',
            'kt', 'lock', 'log', 'md', 'php', 'plist', 'properties', 'py', 'rb', 'rst', 'sh', 'sql', 'swift', 'text',
            'toml', 'tsv', 'txt', 'xml', 'yaml', 'yml', 'zsh',
        ], true);
    }
}
