<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Testing;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Emits the NDJSON event stream the reporting proxy records into. Fully dormant
 * unless TESSERACT_TEST_REPORT names an output file — every entry point
 * short-circuits on the first line when it is unset, and the proxy itself is
 * never even built without the env var, so a normal `artisan test` run is
 * byte-for-byte unaffected.
 *
 * Two lines bracket each step — a `running` line at begin() and a terminal line
 * at finish() — paired by (test, dataset, seq). See docs/test_runner.md §3.2.
 *
 * @internal Tesseract test-support (carried via _nativebackup/).
 */
final class TestStepReporter
{
    /** Tables that should not be copied from the in-process test DB to device. */
    private const STATE_TABLE_DENYLIST = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
        'personal_access_tokens',
        'sqlite_sequence',
    ];

    /** Resolved once from TESSERACT_TEST_REPORT (null = not yet resolved). */
    private static ?bool $enabled = null;

    private static ?string $path = null;

    /** Reentrancy depth so nested harness calls report only the outermost step. */
    private static int $depth = 0;

    /** Last-seen "test\0dataset" identity — a safety net that heals a leaked depth. */
    private static ?string $identity = null;

    /** @var array<string, int> Next seq number per identity. */
    private static array $seq = [];

    /**
     * Open a step. The proxy supplies the phase + verb + resolved args; the
     * source line and the running-test identity are recovered from the call
     * stack. Returns an opaque span handle for finish().
     *
     * @param  list<mixed>  $args
     */
    public static function begin(string $phase, string $method, array $args, ?array $mirror = null): object
    {
        if (! self::enabled()) {
            return self::inert(false);
        }

        try {
            [$identity, $test, $dataset, $line] = self::locate();
        } catch (\Throwable) {
            // The reporter must never break a real test.
            return self::inert(false);
        }

        // A new test starting while depth is non-zero means a previous step
        // leaked (should not happen — every step is bracketed — but heal it).
        if ($identity !== self::$identity) {
            self::$depth = 0;
            self::$identity = $identity;
        }

        self::$depth++;

        if (self::$depth > 1) {
            // Nested harness call (press → fireEvent, assertTabActive →
            // assertHasTab, follow → a new mount). Report only the outer step.
            return self::inert(true);
        }

        $seq = self::$seq[$identity] = (self::$seq[$identity] ?? -1) + 1;

        $event = [
            'v' => 1,
            'test' => $test,
            'dataset' => $dataset,
            'seq' => $seq,
            'phase' => $phase,
            'method' => $method,
            'args' => array_map(static fn ($value) => self::summarize($value, 0), $args),
            'result' => 'running',
            'message' => null,
            'ms' => null,
            'line' => $line,
        ];

        if ($phase === 'entry') {
            $event['state'] = [
                'database' => self::databaseSnapshot(),
            ];
        }

        $normalizedMirror = $mirror !== null ? self::normalizeMirrorCommand($mirror) : null;

        if ($normalizedMirror !== null) {
            $event['mirror'] = $normalizedMirror;
        }

        self::write($event);

        $span = (object) [
            'emit' => true,
            'inc' => true,
            'seq' => $seq,
            'test' => $test,
            'dataset' => $dataset,
            'start' => hrtime(true),
        ];

        if ($normalizedMirror !== null) {
            $span->mirror = $normalizedMirror;
        }

        return $span;
    }

    public static function finish(object $span, string $result, ?string $message): void
    {
        if (! self::enabled()) {
            return;
        }

        if (($span->inc ?? false) === true) {
            self::$depth = max(0, self::$depth - 1);
        }

        if (($span->emit ?? false) !== true) {
            return;
        }

        $event = [
            'v' => 1,
            'test' => $span->test,
            'dataset' => $span->dataset,
            'seq' => $span->seq,
            'phase' => null,
            'method' => null,
            'args' => null,
            'result' => $result,
            'message' => self::clip($message),
            'ms' => round((hrtime(true) - $span->start) / 1e6, 3),
            'line' => null,
        ];

        if (is_array($span->mirror ?? null)) {
            $event['mirror'] = $span->mirror;
        }

        self::write($event);
    }

    /** @param array{kind?: mixed, payload?: mixed} $command */
    public static function attachMirror(object $span, array $command): void
    {
        if (($span->emit ?? false) !== true) {
            return;
        }

        $mirror = self::normalizeMirrorCommand($command);

        if ($mirror !== null) {
            $span->mirror = $mirror;
        }
    }

    /** @param array{kind?: mixed, payload?: mixed} $command */
    private static function normalizeMirrorCommand(array $command): ?array
    {
        if (! is_string($command['kind'] ?? null)) {
            return null;
        }

        $payload = $command['payload'] ?? [];

        return [
            'kind' => $command['kind'],
            'payload' => is_array($payload) ? self::mirrorValue($payload, 0) : [],
        ];
    }

    private static function enabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        $path = getenv('TESSERACT_TEST_REPORT');

        if (! is_string($path) || $path === '') {
            return self::$enabled = false;
        }

        self::$path = $path;

        return self::$enabled = true;
    }

    /** A no-op span. $counted marks whether it incremented the depth. */
    private static function inert(bool $counted): object
    {
        return (object) ['emit' => false, 'inc' => $counted];
    }

    /** Snapshot the test database after user setup, before the entry mount. */
    private static function databaseSnapshot(): ?array
    {
        try {
            if (! class_exists(DB::class)
                || ! class_exists(Schema::class)) {
                return null;
            }

            $connectionName = DB::getDefaultConnection();
            $connection = DB::connection($connectionName);
            $schema = Schema::connection($connectionName);
            $tables = [];

            foreach (self::tableNames($schema) as $table) {
                if (in_array(strtolower($table), self::STATE_TABLE_DENYLIST, true)) {
                    continue;
                }

                $rows = $connection->table($table)
                    ->get()
                    ->map(static fn (object $row): array => self::jsonSafeRow((array) $row))
                    ->all();

                $tables[] = [
                    'name' => $table,
                    'rows' => $rows,
                ];
            }

            $authId = class_exists(Auth::class)
                ? Auth::id()
                : null;

            return [
                'connection' => $connectionName,
                'driver' => $connection->getDriverName(),
                'tables' => $tables,
                'auth' => ['id' => $authId],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function tableNames(mixed $schema): array
    {
        if (method_exists($schema, 'getTableListing')) {
            return array_values(array_unique(array_filter(array_map(
                static fn (mixed $table): ?string => self::normalizeTableName(is_string($table) ? $table : null),
                $schema->getTableListing(),
            ))));
        }

        if (! method_exists($schema, 'getTables')) {
            return [];
        }

        $names = [];

        foreach ($schema->getTables() as $table) {
            $name = null;

            if (is_string($table)) {
                $name = $table;
            } elseif (is_array($table)) {
                $name = $table['name'] ?? $table['table'] ?? null;
            }

            if (is_string($name) && ($normalized = self::normalizeTableName($name)) !== null) {
                $names[] = $normalized;
            }
        }

        return array_values(array_unique($names));
    }

    private static function normalizeTableName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        if (str_contains($name, '.')) {
            $parts = explode('.', $name);
            $name = (string) end($parts);
        }

        return $name !== '' && ! str_starts_with($name, 'sqlite_') ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function jsonSafeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            $row[$key] = self::mirrorValue($value, 0);
        }

        return $row;
    }

    /**
     * Resolve the reporting identity + source line from the call stack: the
     * first test-file frame gives the line (the snapshotPath() technique) and
     * the running TestCase gives the test name + dataset row.
     *
     * @return array{0: string, 1: string, 2: string|null, 3: int}
     */
    private static function locate(): array
    {
        $line = 0;
        $test = '';
        $dataset = null;

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $file = $frame['file'] ?? '';

            if ($line === 0
                && $file !== ''
                && str_contains($file, DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR)
                && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                $line = $frame['line'] ?? 0;
            }

            $object = $frame['object'] ?? null;

            if ($object instanceof TestCase) {
                $test = self::testName($object);
                $dataset = self::datasetName($object);
                break;
            }
        }

        return [$test."\0".($dataset ?? ''), $test, $dataset, $line];
    }

    private static function testName(TestCase $test): string
    {
        $name = method_exists($test, 'name') ? $test->name() : $test->getName();

        return (string) preg_replace('/^__pest_evaluable_/', '', $name);
    }

    private static function datasetName(TestCase $test): ?string
    {
        if (! method_exists($test, 'dataName')) {
            return null;
        }

        $data = $test->dataName();

        if (is_int($data)) {
            return '#'.$data;
        }

        return $data === '' ? null : $data;
    }

    /**
     * Reduce a resolved argument to a JSON-safe value. A large string is
     * recorded as {__big, len, preview} (length + a short preview, never the
     * whole payload); arrays/objects are shallow-summarized.
     */
    private static function summarize(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            if (strlen($value) > 200) {
                return ['__big' => true, 'len' => strlen($value), 'preview' => substr($value, 0, 120)];
            }

            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            if ($depth >= 3) {
                return ['__array' => count($value)];
            }

            $out = [];
            $seen = 0;

            foreach ($value as $key => $item) {
                if ($seen++ >= 20) {
                    $out['__more'] = count($value) - 20;
                    break;
                }

                $out[$key] = self::summarize($item, $depth + 1);
            }

            return $out;
        }

        if (is_object($value)) {
            return ['__object' => get_class($value)];
        }

        return ['__type' => gettype($value)];
    }

    private static function mirrorValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            if ($depth >= 12) {
                return ['__array' => count($value)];
            }

            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = self::mirrorValue($item, $depth + 1);
            }

            return $out;
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return ['__object' => get_class($value)];
        }

        return ['__type' => gettype($value)];
    }

    private static function clip(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return strlen($message) > 2000 ? substr($message, 0, 2000).'…' : $message;
    }

    /** @param array<string, mixed> $event */
    private static function write(array $event): void
    {
        if (self::$path === null) {
            return;
        }

        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($line === false) {
            return;
        }

        file_put_contents(self::$path, $line."\n", FILE_APPEND | LOCK_EX);
    }
}
