<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector;

/**
 * Reads the pairing file the Tesseract desktop writes for this launch.
 *
 * The desktop allocates the relay/websocket ports per launch, sets up the
 * `adb reverse` tunnels, and delivers `pairing.json` to the device at
 * `base_path()/.tesseract/pairing.json` (the same path it lives at on the dev
 * machine in local mode). It carries the authoritative connection details the
 * agent must use — most importantly `project_id`, which the desktop matches
 * against to flip a launch from "launching" to "connected".
 */
class Pairing
{
    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        $path = base_path().DIRECTORY_SEPARATOR.'.tesseract'.DIRECTORY_SEPARATOR.'pairing.json';

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
