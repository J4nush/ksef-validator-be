<?php

declare(strict_types=1);

use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Layer;

covers(ValidationContext::class);

/**
 * @param  list<Layer>  $layers
 */
function enteredContext(array $layers): ValidationContext
{
    $context = new ValidationContext('<a/>');

    foreach ($layers as $layer) {
        $context->enter($layer);
    }

    return $context;
}

it('starts empty', function () {
    $context = new ValidationContext('<a/>');

    expect($context->bytes)->toBe('<a/>')
        ->and($context->findings())->toBe([])
        ->and($context->skipped())->toBe([])
        ->and($context->isUnsupported())->toBeFalse()
        ->and($context->document)->toBeNull()
        ->and($context->invoice)->toBeNull()
        ->and($context->type)->toBeNull()
        ->and(layerStatuses($context))->toBe([
            'security' => 'not_run',
            'recognition' => 'not_run',
            'schema' => 'not_run',
            'rules' => 'not_run',
            'amounts' => 'not_run',
        ]);
});

it('collects findings and skipped checks in order', function () {
    $factory = findingFactory();
    $context = new ValidationContext('<a/>');
    $first = $factory->create(Layer::Security, 'XML-EMPTY');
    $second = $factory->create(Layer::Security, 'SEC-DOCTYPE');
    $skipped = $factory->skipped(null, 'KSEF-DUPLICATE');

    $context->add($first);
    $context->add($second);
    $context->skip($skipped);

    expect($context->findings())->toBe([$first, $second])
        ->and($context->skipped())->toBe([$skipped]);
});

it('remembers that the document is unsupported', function () {
    $context = new ValidationContext('<a/>');
    $context->markUnsupported();

    expect($context->isUnsupported())->toBeTrue();
});

it('lists the five layers in a fixed order whatever order they ran in', function () {
    $context = enteredContext([Layer::Amounts, Layer::Security, Layer::Security, Layer::Rules]);

    expect(layerStatuses($context))->toBe([
        'security' => 'passed',
        'recognition' => 'not_run',
        'schema' => 'not_run',
        'rules' => 'passed',
        'amounts' => 'passed',
    ]);
});

it('derives the status of a layer from its own findings', function (array $findings, string $status) {
    $context = enteredContext(Layer::cases());

    foreach ($findings as $finding) {
        assert(is_array($finding));
        [$layer, $id] = $finding;
        assert($layer instanceof Layer && is_string($id));
        $context->add(findingFactory()->create($layer, $id, params: ['id' => 'X', 'shown' => 1, 'total' => 2]));
    }

    expect(layerStatuses($context)['rules'])->toBe($status);
})->with([
    'nothing' => [[], 'passed'],
    'info only' => [[[Layer::Rules, 'RULE-TRUNCATED']], 'passed'],
    'warning' => [[[Layer::Rules, 'TECH-LINE-NUMBER-DUPLICATE']], 'warnings'],
    'warning and info' => [[[Layer::Rules, 'RULE-TRUNCATED'], [Layer::Rules, 'TECH-LINE-NUMBER-DUPLICATE']], 'warnings'],
    'error' => [[[Layer::Rules, 'TECH-NIP-CHECKSUM']], 'errors'],
    'warning then error' => [[[Layer::Rules, 'TECH-LINE-NUMBER-DUPLICATE'], [Layer::Rules, 'TECH-NIP-CHECKSUM']], 'errors'],
    'error then warning' => [[[Layer::Rules, 'TECH-NIP-CHECKSUM'], [Layer::Rules, 'TECH-LINE-NUMBER-DUPLICATE']], 'errors'],
    'error in another layer' => [[[Layer::Security, 'TECH-UTF8-BOM'], [Layer::Amounts, 'AMT-TOTAL']], 'passed'],
]);

it('marks a layer that skipped its own checks as not checked', function () {
    $context = enteredContext(Layer::cases());
    $context->skip(findingFactory()->skipped(Layer::Amounts, 'AMT-NOT-CHECKED', ['reason' => 'korekta']));
    $context->skip(findingFactory()->skipped(null, 'KSEF-DUPLICATE'));

    expect(layerStatuses($context))->toBe([
        'security' => 'passed',
        'recognition' => 'passed',
        'schema' => 'passed',
        'rules' => 'passed',
        'amounts' => 'not_checked',
    ]);
});

it('prefers errors and warnings over a skipped check', function (string $id, string $status) {
    $context = enteredContext(Layer::cases());
    $context->skip(findingFactory()->skipped(Layer::Amounts, 'AMT-NOT-CHECKED', ['reason' => 'korekta']));
    $context->add(findingFactory()->create(Layer::Amounts, $id));

    expect(layerStatuses($context)['amounts'])->toBe($status);
})->with([
    'warning' => ['AMT-TOTAL', 'warnings'],
    'error' => ['TECH-NIP-CHECKSUM', 'errors'],
    'info' => ['RULE-TRUNCATED', 'not_checked'],
]);

it('marks only the recognition layer as unsupported', function () {
    $context = enteredContext([Layer::Security, Layer::Recognition]);
    $context->add(findingFactory()->create(Layer::Recognition, 'DOC-FA2-UNSUPPORTED'));
    $context->markUnsupported();

    expect(layerStatuses($context))->toBe([
        'security' => 'passed',
        'recognition' => 'unsupported',
        'schema' => 'not_run',
        'rules' => 'not_run',
        'amounts' => 'not_run',
    ]);
});
