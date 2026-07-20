<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Sql;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Executes SQL against the app's configured connections and shapes the result
 * the way the Tesseract desktop SQL runner expects. Mirrors the WebView
 * collector's executor so the desktop UI parses native results identically.
 */
class SqlExecutor
{
    /**
     * @return array{
     *     defaultConnection: string|null,
     *     connections: array<int, array{id: string, label: string, driver: string|null, isDefault: bool}>
     * }
     */
    public function availableConnections(): array
    {
        $configuredConnections = (array) config('database.connections');
        $defaultConnection = config('database.default');
        $orderedConnections = array_values(array_filter(
            array_keys($configuredConnections),
            static fn (mixed $name): bool => is_string($name) && trim($name) !== '',
        ));

        if (is_string($defaultConnection) && in_array($defaultConnection, $orderedConnections, true)) {
            $orderedConnections = [
                $defaultConnection,
                ...array_values(array_filter(
                    $orderedConnections,
                    static fn (string $name): bool => $name !== $defaultConnection,
                )),
            ];
        }

        return [
            'defaultConnection' => is_string($defaultConnection) ? $defaultConnection : null,
            'connections' => array_map(function (string $connection) use ($configuredConnections, $defaultConnection): array {
                $config = (array) ($configuredConnections[$connection] ?? []);
                $driver = is_string($config['driver'] ?? null) ? $config['driver'] : null;

                return [
                    'id' => $connection,
                    'label' => $connection,
                    'driver' => $driver,
                    'isDefault' => $connection === $defaultConnection,
                ];
            }, $orderedConnections),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        string $sql,
        string $connection,
        ?string $sourceQueryId = null,
        int $maxRows = 200,
    ): array {
        $statement = trim($sql);
        $statementType = $this->detectStatementType($statement);
        $submittedAt = CarbonImmutable::now();
        $startedAt = CarbonImmutable::now();
        $result = null;
        $returnedRowCount = 0;
        $affectedRowCount = 0;
        $truncated = false;
        $notices = [];

        if ($this->returnsRows($statementType)) {
            $rows = $this->fetchRows($connection, $statement, $maxRows);
            $returnedRowCount = count($rows['rows']);
            $truncated = $rows['truncated'];
            $columns = $this->buildColumns($rows['rows']);
            $result = [
                'columns' => $columns,
                'rows' => array_map(
                    fn (array $row): array => $this->normalizeRow($row, $columns),
                    $rows['rows'],
                ),
            ];

            if ($truncated) {
                $notices[] = [
                    'level' => 'warning',
                    'code' => 'row-limit-truncated',
                    'message' => "Results were truncated to the first {$maxRows} rows.",
                ];
            }
        } else {
            $affectedRowCount = $this->executeMutation($connection, $statement, $statementType);
        }

        $completedAt = CarbonImmutable::now();
        $elapsedMs = max((int) ($completedAt->valueOf() - $startedAt->valueOf()), 0);

        return [
            'execution' => [
                'id' => 'sql-'.Str::lower((string) Str::ulid()),
                'status' => 'success',
                'connection' => $connection,
                'statementType' => $statementType,
                'submittedAt' => $this->formatTimestamp($submittedAt),
                'startedAt' => $this->formatTimestamp($startedAt),
                'completedAt' => $this->formatTimestamp($completedAt),
                'elapsedMs' => $elapsedMs,
                'returnedRowCount' => $returnedRowCount,
                'affectedRowCount' => $affectedRowCount,
                'truncated' => $truncated,
                'sourceQueryId' => $sourceQueryId,
            ],
            'result' => $result,
            'notices' => $notices,
            'meta' => [
                'databaseLabel' => $this->databaseLabel($connection),
                'databaseTarget' => $this->databaseTarget($connection),
                'transactionState' => $this->transactionState($connection, $statementType),
                'serverVersion' => $this->serverVersion($connection),
                'mock' => false,
            ],
        ];
    }

    protected function detectStatementType(string $statement): string
    {
        if (preg_match('/^\s*(\w+)/', $statement, $matches) !== 1) {
            return 'SELECT';
        }

        $keyword = strtoupper((string) $matches[1]);

        return $keyword === 'WITH' ? 'SELECT' : $keyword;
    }

    protected function returnsRows(string $statementType): bool
    {
        return in_array($statementType, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'PRAGMA'], true);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool}
     */
    protected function fetchRows(string $connection, string $statement, int $maxRows): array
    {
        $prepared = DB::connection($connection)->getPdo()->prepare($statement);

        if ($prepared === false) {
            throw new RuntimeException('SQL statement could not be prepared.');
        }

        $prepared->execute();

        $rows = [];
        $truncated = false;

        while (($row = $prepared->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (count($rows) >= $maxRows) {
                $truncated = true;

                break;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    protected function executeMutation(string $connection, string $statement, string $statementType): int
    {
        $database = DB::connection($connection);

        return match ($statementType) {
            'INSERT', 'UPDATE', 'DELETE' => $database->affectingStatement($statement),
            default => $database->statement($statement) ? 0 : 0,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{key: string, label: string, type: string, align: string}>
     */
    protected function buildColumns(array $rows): array
    {
        $keys = [];

        foreach ($rows as $row) {
            $keys = array_values(array_unique([...$keys, ...array_keys($row)]));
        }

        return array_map(function (string $key) use ($rows): array {
            $type = $this->inferColumnType($key, $rows);

            return [
                'key' => $key,
                'label' => Str::of($key)->replace('_', ' ')->lower()->value(),
                'type' => $type,
                'align' => in_array($type, ['integer', 'decimal'], true) ? 'right' : 'left',
            ];
        }, $keys);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array{key: string, label: string, type: string, align: string}>  $columns
     * @return array<string, mixed>
     */
    protected function normalizeRow(array $row, array $columns): array
    {
        $normalized = [];

        foreach ($columns as $column) {
            $key = $column['key'];
            $normalized[$key] = $this->normalizeCellValue($row[$key] ?? null);
        }

        return $normalized;
    }

    protected function normalizeCellValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value)) {
            return (array) $value;
        }

        return $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function inferColumnType(string $key, array $rows): string
    {
        foreach ($rows as $row) {
            $value = $row[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (is_int($value)) {
                return 'integer';
            }

            if (is_float($value)) {
                return 'decimal';
            }

            if (is_bool($value)) {
                return 'boolean';
            }

            if (is_array($value) || is_object($value)) {
                return 'json';
            }
        }

        $normalized = Str::lower($key);

        if (Str::contains($normalized, ['payload', 'meta', 'json'])) {
            return 'json';
        }

        if (Str::startsWith($normalized, ['is_', 'has_'])) {
            return 'boolean';
        }

        if (Str::endsWith($normalized, ['_at', '_on']) || Str::contains($normalized, ['date', 'time'])) {
            return 'timestamp';
        }

        if ($normalized === 'id' || Str::endsWith($normalized, '_id') || Str::contains($normalized, ['count', 'rows', 'qty', 'ms'])) {
            return 'integer';
        }

        if (Str::contains($normalized, ['total', 'amount', 'price', 'fee'])) {
            return 'decimal';
        }

        return 'text';
    }

    protected function databaseLabel(string $connection): string
    {
        $config = (array) config("database.connections.{$connection}");
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : $connection;

        return match ($driver) {
            'sqlite' => 'SQLite',
            'pgsql' => 'PostgreSQL',
            'sqlsrv' => 'SQL Server',
            'mysql', 'mariadb' => 'MySQL',
            default => Str::title($connection),
        };
    }

    protected function databaseTarget(string $connection): ?string
    {
        $config = (array) config("database.connections.{$connection}");
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : null;

        if ($driver === 'sqlite') {
            $database = is_string($config['database'] ?? null) ? $config['database'] : null;

            if ($database === null || $database === '') {
                return null;
            }

            $resolved = realpath($database);

            return $resolved !== false ? $resolved : $database;
        }

        $database = is_string($config['database'] ?? null) ? trim($config['database']) : '';
        $host = is_string($config['host'] ?? null) ? trim($config['host']) : '';
        $port = is_string($config['port'] ?? null) || is_int($config['port'] ?? null) ? (string) $config['port'] : '';

        if ($database === '' && $host === '') {
            return null;
        }

        $target = $database !== '' ? $database : $connection;

        if ($host !== '') {
            $target .= "@{$host}";

            if ($port !== '') {
                $target .= ":{$port}";
            }
        }

        return $target;
    }

    protected function transactionState(string $connection, string $statementType): string
    {
        try {
            if (DB::connection($connection)->getPdo()->inTransaction()) {
                return 'transaction';
            }
        } catch (Throwable) {
            //
        }

        return $this->returnsRows($statementType) ? 'idle' : 'auto-commit';
    }

    protected function serverVersion(string $connection): ?string
    {
        try {
            $pdo = DB::connection($connection)->getPdo();
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

            return is_string($version) ? $version : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function formatTimestamp(CarbonImmutable $time): string
    {
        return $time->format('Y-m-d\TH:i:s.vP');
    }
}
