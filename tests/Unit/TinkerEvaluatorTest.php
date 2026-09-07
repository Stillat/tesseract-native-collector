<?php

declare(strict_types=1);

use Tesseract\NativeCollector\Tinker\TinkerEvaluator as NativeTinkerEvaluator;

it('emits structured payloads for complex native tinker results', function (): void {
    $evaluation = (new NativeTinkerEvaluator)->evaluateCode(
        '(object) [
            "name" => "FacebookFeed",
            "posts" => [
                ["id" => 1, "liked" => true],
                ["id" => 2, "liked" => false],
            ],
            "meta" => ["page" => 2],
        ]',
    );

    $structuredPayload = $evaluation['structuredPayload'] ?? null;

    expect($evaluation['status'])->toBe('success')
        ->and($evaluation['resultType'])->toBe('object')
        ->and($evaluation['fullPayload'])->toContain('FacebookFeed')
        ->and($structuredPayload)->toBeArray()
        ->and($structuredPayload['format'])->toBe('json');

    $tree = json_decode(
        $structuredPayload['value'],
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($tree['__meta'])->toMatchArray([
        'kind' => 'object',
        'class' => 'stdClass',
    ])
        ->and($tree['dynamic:name'])->toBe('FacebookFeed')
        ->and($tree['dynamic:posts']['__meta'])->toMatchArray([
            'kind' => 'array',
            'shape' => 'indexed',
            'items' => 2,
        ])
        ->and($tree['dynamic:posts']['0']['id'])->toBe(1)
        ->and($tree['dynamic:posts']['0']['liked'])->toBeTrue()
        ->and($tree['dynamic:posts']['1']['liked'])->toBeFalse()
        ->and($tree['dynamic:meta']['page'])->toBe(2);
});

it('keeps scalar native tinker results compact', function (): void {
    $evaluation = (new NativeTinkerEvaluator)->evaluateCode('21 * 2');

    expect($evaluation['status'])->toBe('success')
        ->and($evaluation['resultType'])->toBe('integer')
        ->and($evaluation['resultPreview'])->toBe('42')
        ->and($evaluation['fullPayload'])->toBe('42')
        ->and($evaluation)->not->toHaveKey('structuredPayload');
});

it('evaluates complete native tinker submissions', function (string $code, string $type, string $result): void {
    $evaluation = (new NativeTinkerEvaluator)->evaluateCode($code);

    expect($evaluation['status'])->toBe('success')
        ->and($evaluation['resultType'])->toBe($type)
        ->and($evaluation['fullPayload'])->toBe($result);
})->with([
    'multiple assignments and explicit return' => ['$value = 1; $value = 2; return $value;', 'integer', '2'],
    'statements without a return' => ['$value = 1; $value = 2;', 'null', 'NULL'],
    'output after the first expression' => ['$value = 1; echo "completed"; return $value + 1;', 'integer', 'completed'.PHP_EOL.PHP_EOL.'2'],
    'a single assignment expression' => ['$value = 42;', 'integer', '42'],
    'semicolons inside a closure expression' => ['(static function () { $value = 1; return ++$value; })()', 'integer', '2'],
]);

it('does not execute expression probes or stop before later side effects', function (): void {
    $GLOBALS['native_tinker_evaluations'] = 0;

    try {
        $evaluation = (new NativeTinkerEvaluator)->evaluateCode(
            '$GLOBALS["native_tinker_evaluations"]++; $GLOBALS["native_tinker_evaluations"]++; return $GLOBALS["native_tinker_evaluations"];',
        );

        expect($evaluation['status'])->toBe('success')
            ->and($evaluation['fullPayload'])->toBe('2')
            ->and($GLOBALS['native_tinker_evaluations'])->toBe(2);
    } finally {
        unset($GLOBALS['native_tinker_evaluations']);
    }
});
