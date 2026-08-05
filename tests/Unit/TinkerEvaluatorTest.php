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
