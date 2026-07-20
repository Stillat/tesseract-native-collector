<?php

declare(strict_types=1);

namespace Tesseract\NativeCollector\Tinker;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\Stub;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Throwable;

/**
 * Evaluates a PHP snippet in a fresh, isolated scope and shapes the result the
 * Tesseract desktop console expects: { tone, status, resultType, resultPreview,
 * fullPayload }.
 *
 * Each submission is fully stateless — no variables or imports carry across
 * calls — matching the WebView collector's stateless evaluator. The rich
 * structured-object payload the collector also emits is intentionally omitted
 * here (it is optional on the wire); the preview + CliDumper full payload cover
 * the common cases.
 */
class TinkerEvaluator
{
    protected const STRUCTURED_PAYLOAD_MAX_DEPTH = 6;

    protected const STRUCTURED_PAYLOAD_MAX_ITEMS = 50;

    protected const MAX_EVAL_SECONDS = 8;

    /**
     * @return array<string, mixed>
     */
    public function evaluateCode(string $code): array
    {
        try {
            $prepared = $this->prepareCode($code);
            [$result, $capturedOutput] = $this->evaluatePreparedCode($prepared);

            return $this->formatSuccessfulEvaluation($result, $capturedOutput);
        } catch (Throwable $exception) {
            $message = $this->jsonSafeString($exception->getMessage());
            $trace = $this->jsonSafeString($exception->getTraceAsString());

            return [
                'tone' => 'error',
                'status' => 'error',
                'resultType' => 'exception',
                'resultPreview' => $exception::class.': '.$message,
                'fullPayload' => trim($message.PHP_EOL.PHP_EOL.$trace),
            ];
        }
    }

    /**
     * @return array{code: string, mode: string}
     */
    protected function prepareCode(string $code): array
    {
        $trimmedCode = $this->normalizeSnippet(trim($code));
        $imports = $this->extractUseImports($trimmedCode);
        $resolvedCode = trim($imports['code']);
        $setupStatements = trim($imports['setup']);
        $expressionCandidate = rtrim($resolvedCode, " \t\n\r\0\x0B;");

        if ($expressionCandidate !== '' && $this->compilesAsExpression($expressionCandidate)) {
            $preparedExpression = $setupStatements !== ''
                ? $setupStatements.' return '.$expressionCandidate.';'
                : 'return '.$expressionCandidate.';';

            return ['code' => $preparedExpression, 'mode' => 'expression'];
        }

        $statementCode = trim($setupStatements.($resolvedCode !== '' ? ' '.$resolvedCode : ''));

        if ($statementCode === '') {
            $statementCode = 'return null;';
        } elseif (! Str::endsWith(rtrim($statementCode), ';')) {
            $statementCode .= ';';
        }

        return ['code' => $statementCode.' return null;', 'mode' => 'statement'];
    }

    protected function normalizeSnippet(string $code): string
    {
        if ($code === '' || ! Str::startsWith($code, ['<?php', '<?=', '<?'])) {
            return $code;
        }

        $normalized = preg_replace('/^<\?(?:php|=)?\s*/i', '', $code, 1);

        if (! is_string($normalized)) {
            return $code;
        }

        $normalized = preg_replace('/\?>\s*$/', '', $normalized, 1);

        return is_string($normalized) ? trim($normalized) : trim($code);
    }

    protected function compilesAsExpression(string $expression): bool
    {
        try {
            eval("return static function (): mixed { return {$expression}; };");

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array{code: string, mode: string}  $prepared
     * @return array{0: mixed, 1: string}
     */
    protected function evaluatePreparedCode(array $prepared): array
    {
        $baseOutputBufferLevel = ob_get_level();
        $previousTimeLimit = (int) ini_get('max_execution_time');
        $this->applyTimeLimit(self::MAX_EVAL_SECONDS);
        ob_start();

        try {
            $result = eval($prepared['code']);
        } finally {
            $capturedOutput = trim($this->closeEvaluationOutputBuffers($baseOutputBufferLevel));
            $this->applyTimeLimit($previousTimeLimit);
        }

        return [$result, $capturedOutput];
    }

    protected function applyTimeLimit(int $seconds): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(max($seconds, 0));
        }
    }

    protected function closeEvaluationOutputBuffers(int $baseOutputBufferLevel): string
    {
        $capturedOutput = '';

        while (ob_get_level() > $baseOutputBufferLevel) {
            $levelBeforeClose = ob_get_level();
            $chunk = @ob_get_clean();

            if (is_string($chunk) && $chunk !== '') {
                $capturedOutput = $chunk.$capturedOutput;
            }

            if (ob_get_level() >= $levelBeforeClose) {
                break;
            }
        }

        return $capturedOutput;
    }

    /**
     * @return array{setup: string, code: string}
     */
    protected function extractUseImports(string $code): array
    {
        $setup = [];
        $resolvedCode = ltrim($code);

        while (preg_match('/^use\s+([^;]+);\s*/', $resolvedCode, $matches) === 1) {
            $definition = trim((string) ($matches[1] ?? ''));

            if (
                $definition !== ''
                && preg_match('/^(?<class>[\\\\A-Za-z0-9_]+)(?:\s+as\s+(?<alias>[A-Za-z_][A-Za-z0-9_]*))?$/i', $definition, $parts) === 1
            ) {
                $class = ltrim((string) $parts['class'], '\\');
                $alias = isset($parts['alias']) && is_string($parts['alias']) && $parts['alias'] !== ''
                    ? $parts['alias']
                    : Str::afterLast($class, '\\');

                $setup[] = sprintf(
                    "if (!class_exists('%s', false) && !interface_exists('%s', false) && !trait_exists('%s', false)) { class_alias('%s', '%s'); }",
                    $alias, $alias, $alias, $class, $alias,
                );
            }

            $resolvedCode = ltrim((string) substr($resolvedCode, strlen((string) $matches[0])));
        }

        return ['setup' => implode(' ', $setup), 'code' => $resolvedCode];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatSuccessfulEvaluation(mixed $result, string $capturedOutput): array
    {
        $capturedOutput = $this->jsonSafeString($capturedOutput);
        $resultType = $this->resultType($result, $capturedOutput);
        $payload = $this->payloadSnapshotForValue($result);
        $preview = $this->previewForValue($result, $payload['raw'], $capturedOutput);
        $fullPayload = $capturedOutput !== ''
            ? trim($capturedOutput.($payload['raw'] !== '' ? PHP_EOL.PHP_EOL.$payload['raw'] : ''))
            : $payload['raw'];

        $evaluation = [
            'tone' => 'result',
            'status' => 'success',
            'resultType' => $resultType,
            'resultPreview' => $preview,
            'fullPayload' => $fullPayload !== '' ? $fullPayload : 'null',
        ];

        if ($payload['structured'] !== null) {
            $evaluation['structuredPayload'] = $payload['structured'];
        }

        return $evaluation;
    }

    protected function resultType(mixed $result, string $capturedOutput): string
    {
        if ($capturedOutput !== '' && $result === null) {
            return 'output';
        }

        return match (true) {
            $result instanceof Throwable => 'exception',
            is_int($result) => 'integer',
            is_float($result) => 'decimal',
            is_bool($result) => 'boolean',
            is_string($result) => 'string',
            $result === null => 'null',
            $result instanceof Model => 'model',
            $result instanceof Collection => 'collection',
            is_array($result) => 'array',
            is_object($result) => 'object',
            is_resource($result) => 'resource',
            default => get_debug_type($result),
        };
    }

    protected function previewForValue(mixed $result, string $raw, string $capturedOutput): string
    {
        if ($capturedOutput !== '' && $result === null) {
            return Str::limit(Str::squish($capturedOutput), 160, '...');
        }

        return match (true) {
            is_string($result) => "'".Str::limit($this->jsonSafeString($result), 120, '...')."'",
            is_bool($result) => $result ? 'true' : 'false',
            is_int($result), is_float($result) => (string) $result,
            $result === null => 'null',
            default => Str::limit(Str::squish($raw), 160, '...'),
        };
    }

    /**
     * @return array{
     *     raw: string,
     *     structured: array{
     *         format: string,
     *         value: string,
     *         truncated?: bool
     *     }|null
     * }
     */
    protected function payloadSnapshotForValue(mixed $value): array
    {
        if (is_string($value)) {
            return [
                'raw' => $this->jsonSafeString($value),
                'structured' => null,
            ];
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return [
                'raw' => var_export($value, true),
                'structured' => null,
            ];
        }

        $data = (new VarCloner)->cloneVar($value);

        return [
            'raw' => $this->rawPayloadFromData($data),
            'structured' => $this->structuredPayloadFromData($data),
        ];
    }

    protected function rawPayloadFromData(Data $data): string
    {
        $dumper = new CliDumper;
        $dumper->setColors(false);
        $buffer = '';

        $dumper->dump(
            $data,
            static function (string $line) use (&$buffer): void {
                $buffer .= $line;
            },
        );

        return trim($this->jsonSafeString($buffer));
    }

    /**
     * @return array{
     *     format: string,
     *     value: string,
     *     truncated?: bool
     * }|null
     */
    protected function structuredPayloadFromData(Data $data): ?array
    {
        $snapshot = $this->dumpSnapshot($data);
        $seenPositions = [];
        $referenceIds = [];
        $nextReferenceIndex = 1;
        $truncated = false;
        $serialized = $this->serializeStructuredItem(
            $snapshot['data'],
            $snapshot['data'][$snapshot['position']][$snapshot['key']],
            0,
            $seenPositions,
            $referenceIds,
            $nextReferenceIndex,
            $truncated,
        );

        if (! is_array($serialized) && ! is_object($serialized)) {
            return null;
        }

        $payload = [
            'format' => 'json',
            'value' => json_encode(
                $serialized,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            ),
        ];

        if ($truncated) {
            $payload['truncated'] = true;
        }

        return $payload;
    }

    /**
     * @param  array<int, array<int|string, mixed>>  $dumpData
     * @param  array<int, string>  $seenPositions
     * @param  array<int, string>  $referenceIds
     */
    protected function serializeStructuredItem(
        array $dumpData,
        mixed $item,
        int $depth,
        array &$seenPositions,
        array &$referenceIds,
        int &$nextReferenceIndex,
        bool &$truncated,
    ): mixed {
        if ($item instanceof Stub && $item->type === Stub::TYPE_REF) {
            $referenceMeta = $this->referenceMetadata(
                $item,
                $referenceIds,
                $nextReferenceIndex,
            );

            if (
                $item->position !== 0
                && array_key_exists($item->position, $seenPositions)
            ) {
                return [
                    '__meta' => array_filter([
                        'kind' => 'reference',
                        'target' => $seenPositions[$item->position],
                        'softRefTo' => $referenceMeta['softRefTo'] ?? null,
                        'hardRefTo' => $referenceMeta['hardRefTo'] ?? null,
                        'hardRefCount' => $referenceMeta['hardRefCount'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ];
            }

            $item = $item->value;
        }

        if (! ($item = $this->normalizeDumpItem($item, $dumpData)) instanceof Stub) {
            return $item;
        }

        if ($item->type === Stub::TYPE_STRING) {
            if ($item->cut <= 0) {
                return $item->value;
            }

            $truncated = true;

            return [
                '__meta' => [
                    'kind' => 'string',
                    'cut' => $item->cut,
                ],
                'value' => $item->value,
            ];
        }

        if ($item->type === Stub::TYPE_SCALAR) {
            return $item->attr['value'] ?? null;
        }

        if ($depth >= self::STRUCTURED_PAYLOAD_MAX_DEPTH) {
            $truncated = true;

            return [
                '__meta' => array_filter([
                    'kind' => 'truncated',
                    'type' => $this->stubTypeLabel($item),
                    'class' => is_string($item->class) && $item->class !== ''
                        ? $item->class
                        : null,
                    'reason' => 'max-depth',
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        $position = $item->position;

        if ($position !== 0 && array_key_exists($position, $seenPositions)) {
            return [
                '__meta' => [
                    'kind' => 'reference',
                    'target' => $seenPositions[$position],
                ],
            ];
        }

        $referenceId = $position !== 0
            ? $this->referenceIdForPosition(
                $position,
                $referenceIds,
                $nextReferenceIndex,
            )
            : null;

        if ($position !== 0 && $referenceId !== null) {
            $seenPositions[$position] = $referenceId;
        }

        $children = $position !== 0 ? ($dumpData[$position] ?? []) : [];
        $visibleChildren = array_slice(
            $children,
            0,
            self::STRUCTURED_PAYLOAD_MAX_ITEMS,
            true,
        );
        $hiddenChildren = max(count($children) - count($visibleChildren), 0);
        $cut = max($item->cut, 0) + $hiddenChildren;
        $node = [
            '__meta' => array_filter([
                'kind' => $this->stubTypeLabel($item),
                'id' => $referenceId,
                'class' => match ($item->type) {
                    Stub::TYPE_OBJECT, Stub::TYPE_RESOURCE => is_string($item->class) && $item->class !== ''
                        ? $item->class
                        : null,
                    default => null,
                },
                'shape' => $item->type === Stub::TYPE_ARRAY
                    ? $this->arrayShapeLabel($item)
                    : null,
                'items' => $this->stubItemCount($item, count($children)),
                'cut' => $cut > 0 ? $cut : null,
                'attributes' => $this->normalizedStubAttributes($item),
            ], static fn (mixed $value): bool => $value !== null),
        ];

        foreach ($visibleChildren as $childKey => $child) {
            $node[$this->normalizeDumpKey($childKey)] = $this->serializeStructuredItem(
                $dumpData,
                $child,
                $depth + 1,
                $seenPositions,
                $referenceIds,
                $nextReferenceIndex,
                $truncated,
            );
        }

        if ($cut > 0) {
            $truncated = true;
            $node['__truncated__'] = sprintf(
                '%d more %s',
                $cut,
                $cut === 1 ? 'item' : 'items',
            );
        }

        return $node;
    }

    protected function stubTypeLabel(Stub $stub): string
    {
        return match ($stub->type) {
            Stub::TYPE_ARRAY => 'array',
            Stub::TYPE_OBJECT => 'object',
            Stub::TYPE_RESOURCE => 'resource',
            default => 'value',
        };
    }

    protected function arrayShapeLabel(Stub $stub): string
    {
        return $stub->class === Stub::ARRAY_INDEXED
            ? 'indexed'
            : 'associative';
    }

    protected function stubItemCount(Stub $stub, int $childCount): int
    {
        if ($stub->type === Stub::TYPE_ARRAY && is_int($stub->value)) {
            return $stub->value;
        }

        return max($childCount + max($stub->cut, 0), 0);
    }

    /**
     * @param  array<int, string>  $referenceIds
     * @return array<string, int|string>|null
     */
    protected function referenceMetadata(
        Stub $stub,
        array &$referenceIds,
        int &$nextReferenceIndex,
    ): ?array {
        $metadata = array_filter([
            'softRefTo' => $stub->refCount > 0 && $stub->handle !== 0
                ? $this->referenceIdForPosition(
                    $stub->handle,
                    $referenceIds,
                    $nextReferenceIndex,
                )
                : null,
            'hardRefTo' => $stub->handle < 0
                ? $this->referenceIdForPosition(
                    $stub->handle,
                    $referenceIds,
                    $nextReferenceIndex,
                )
                : null,
            'hardRefCount' => $stub->handle < 0 && $stub->refCount > 0
                ? $stub->refCount
                : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $metadata === [] ? null : $metadata;
    }

    /**
     * @param  array<int, string>  $referenceIds
     */
    protected function referenceIdForPosition(
        int $position,
        array &$referenceIds,
        int &$nextReferenceIndex,
    ): string {
        if (! array_key_exists($position, $referenceIds)) {
            $referenceIds[$position] = 'ref-'.$nextReferenceIndex;
            $nextReferenceIndex += 1;
        }

        return $referenceIds[$position];
    }

    /**
     * @param  array<int, array<int|string, mixed>>  $dumpData
     */
    protected function normalizeDumpItem(mixed $item, array $dumpData): mixed
    {
        if (! $item || ! is_array($item)) {
            return $item;
        }

        $stub = new Stub;
        $stub->type = Stub::TYPE_ARRAY;

        foreach ($item as $class => $position) {
            $stub->class = $class;
            $stub->position = $position;
        }

        if (isset($item[0])) {
            $stub->cut = $item[0];
        }

        $stub->value = $stub->cut + ($stub->position ? count($dumpData[$stub->position] ?? []) : 0);

        return $stub;
    }

    protected function normalizeDumpKey(int|string $key): string
    {
        if (is_int($key)) {
            return (string) $key;
        }

        if (! Str::startsWith($key, "\0")) {
            return $key;
        }

        if (Str::startsWith($key, Caster::PREFIX_PROTECTED)) {
            return 'protected:'.substr($key, strlen(Caster::PREFIX_PROTECTED));
        }

        if (Str::startsWith($key, Caster::PREFIX_DYNAMIC)) {
            return 'dynamic:'.substr($key, strlen(Caster::PREFIX_DYNAMIC));
        }

        if (Str::startsWith($key, Caster::PREFIX_VIRTUAL)) {
            return 'virtual:'.substr($key, strlen(Caster::PREFIX_VIRTUAL));
        }

        $segments = explode("\0", $key);
        $owner = $segments[1] ?? 'private';
        $property = $segments[2] ?? $key;

        return sprintf('private:%s:%s', $owner, $property);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function normalizedStubAttributes(Stub $stub): ?array
    {
        $attributes = [];

        if (is_string($stub->attr['file'] ?? null) && $stub->attr['file'] !== '') {
            $attributes['file'] = $stub->attr['file'];
        }

        if (is_numeric($stub->attr['line'] ?? null)) {
            $attributes['line'] = (int) $stub->attr['line'];
        }

        return $attributes === [] ? null : $attributes;
    }

    /**
     * @return array{
     *     data: array<int, array<int|string, mixed>>,
     *     position: int,
     *     key: int|string
     * }
     */
    protected function dumpSnapshot(Data $data): array
    {
        return Closure::bind(
            static function (Data $data): array {
                return [
                    'data' => $data->data,
                    'position' => $data->position,
                    'key' => $data->key,
                ];
            },
            null,
            Data::class,
        )($data);
    }

    protected function jsonSafeString(string $value): string
    {
        try {
            $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

            return is_string($decoded) ? $decoded : '';
        } catch (Throwable) {
            return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $value) ?? '';
        }
    }
}
