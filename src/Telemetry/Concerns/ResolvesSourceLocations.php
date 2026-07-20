<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Telemetry\Concerns;

use Throwable;

trait ResolvesSourceLocations
{
    /** @var array<string, string|null> */
    protected array $compiledViewPaths = [];

    protected function relativeProjectPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path()).'/';

        return str_starts_with($normalized, $base)
            ? substr($normalized, strlen($base))
            : $normalized;
    }

    /** @return array<int, array{path: string, line: int|null, column: int|null, functionLabel: string, appFrame: bool, language: string, sourceSnippet: string|null}> */
    protected function frames(Throwable $exception): array
    {
        $frames = [$this->frame(
            $exception->getFile(),
            $exception->getLine(),
            null,
            null,
        )];

        foreach (array_slice($exception->getTrace(), 0, 40) as $frame) {
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : null;

            if ($file === null) {
                continue;
            }

            $frames[] = $this->frame(
                $file,
                isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0,
                isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : null,
                isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null,
            );
        }

        return $frames;
    }

    /**
     * @return array{path: string, line: int|null, column: int|null, functionLabel: string, appFrame: bool, language: string, sourceSnippet: string|null}
     */
    protected function frame(string $file, int $line, ?string $function, ?string $class): array
    {
        $label = $function === null
            ? '(unknown)'
            : ($class !== null ? $class.'::'.$function : $function);

        $mapped = $this->mapSourceLocation($file, $line > 0 ? $line : null);

        return [
            'path' => $mapped['path'],
            'line' => $mapped['line'],
            'column' => null,
            'functionLabel' => $label,
            'appFrame' => ! str_starts_with($mapped['path'], 'vendor/')
                && ! str_contains($mapped['path'], '/vendor/'),
            'language' => 'php',
            'sourceSnippet' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    protected function resolveSourceFrame(): ?array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || $file === '') {
                continue;
            }

            $mapped = $this->mapSourceLocation(
                $file,
                isset($frame['line']) && is_numeric($frame['line']) ? (int) $frame['line'] : null,
            );

            if (
                str_starts_with($mapped['path'], 'vendor/')
                || str_contains(str_replace('\\', '/', $file), '/native_plugin/src/')
            ) {
                continue;
            }

            return [
                'file' => $mapped['path'],
                'line' => $mapped['line'],
                'functionName' => isset($frame['function']) && is_string($frame['function'])
                    ? $frame['function']
                    : null,
                'className' => isset($frame['class']) && is_string($frame['class'])
                    ? $frame['class']
                    : null,
            ];
        }

        return null;
    }

    /** @return array{path: string, line: int|null} */
    protected function mapSourceLocation(string $path, ?int $line): array
    {
        $originalViewPath = $this->originalViewPath($path);
        $displayPath = $this->projectRelativePath($originalViewPath ?? $path);

        return [
            'path' => $displayPath,
            'line' => $originalViewPath !== null ? null : $line,
        ];
    }

    protected function displaySourcePath(string $path): string
    {
        return $this->mapSourceLocation($path, null)['path'];
    }

    protected function originalViewPath(string $path): ?string
    {
        if (! str_contains(str_replace('\\', '/', $path), '/framework/views/')) {
            return null;
        }

        if (array_key_exists($path, $this->compiledViewPaths)) {
            return $this->compiledViewPaths[$path];
        }

        $resolved = null;

        try {
            if (is_file($path)) {
                $size = (int) filesize($path);
                $tail = (string) file_get_contents($path, false, null, max($size - 8192, 0));

                if (preg_match_all('/\/\*\*PATH (.+?) ENDPATH\*\*\//s', $tail, $matches) > 0) {
                    $resolved = trim((string) end($matches[1]));
                }
            }
        } catch (Throwable) {
            $resolved = null;
        }

        return $this->compiledViewPaths[$path] = ($resolved !== null && $resolved !== '' ? $resolved : null);
    }

    protected function projectRelativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        if ($normalized === '') {
            return $path;
        }

        foreach ($this->projectRootPrefixes() as $base) {
            if (str_starts_with($normalized, $base)) {
                return substr($normalized, strlen($base));
            }
        }

        $projectShaped = $this->projectShapedRelativePath($normalized);

        if ($projectShaped !== null) {
            return $projectShaped;
        }

        return $normalized;
    }

    /** @return array<int, string> */
    protected function projectRootPrefixes(): array
    {
        $prefixes = [];
        $candidates = array_filter([
            base_path(),
            realpath(base_path()) ?: null,
        ]);

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $normalized = rtrim(str_replace('\\', '/', $candidate), '/').'/';

            if (! in_array($normalized, $prefixes, true)) {
                $prefixes[] = $normalized;
            }
        }

        return $prefixes;
    }

    protected function projectShapedRelativePath(string $normalizedPath): ?string
    {
        if (! str_starts_with($normalizedPath, '/') && ! preg_match('#^[A-Za-z]:/#', $normalizedPath)) {
            return null;
        }

        if (preg_match(
            '#(?:^|/)(?:laravel/|app_storage/laravel/)?((?:app|resources|routes|config|database|tests|vendor|bootstrap|public|storage)/.+)$#',
            $normalizedPath,
            $matches,
        ) !== 1) {
            return null;
        }

        $relative = $matches[1];

        return $relative !== '' ? $relative : null;
    }

    protected function displayRawTrace(Throwable $exception): string
    {
        $trace = $exception->getTraceAsString();
        $original = $this->originalViewPath($exception->getFile());

        if ($original !== null) {
            $trace = str_replace($exception->getFile(), $original, $trace);
        }

        foreach ($this->projectRootPrefixes() as $base) {
            $trace = str_replace(rtrim($base, '/').'/', '', $trace);
            $trace = str_replace(str_replace('/', DIRECTORY_SEPARATOR, rtrim($base, '/')).DIRECTORY_SEPARATOR, '', $trace);
        }

        return $trace;
    }
}
