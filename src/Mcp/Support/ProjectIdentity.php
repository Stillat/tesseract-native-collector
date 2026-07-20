<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Mcp\Support;

use Tesseract\NativeCollector\Pairing;

/**
 * Resolves the `{key, path}` project identity the desktop uses to answer
 * "latest native session for this project" without the agent tracking a
 * session id.
 *
 * The key mirrors what the agent advertised at connect time (see
 * `TesseractNativeCollectorServiceProvider::igniteAgent`): the desktop's
 * `project_id` from the pairing file when present, otherwise a stable
 * `sha1(base_path())`.
 */
class ProjectIdentity
{
    public function __construct(
        protected Pairing $pairing,
    ) {}

    /**
     * @return array{key: string, path: string}
     */
    public function projectPayload(): array
    {
        $pairing = $this->pairing->read() ?? [];

        $key = $pairing['project_id'] ?? null;
        $path = $pairing['project_path'] ?? null;

        return [
            'key' => is_string($key) && $key !== '' ? $key : sha1((string) base_path()),
            'path' => is_string($path) && $path !== '' ? $path : (string) base_path(),
        ];
    }
}
