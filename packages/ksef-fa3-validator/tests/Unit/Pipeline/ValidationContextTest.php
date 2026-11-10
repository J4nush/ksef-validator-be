<?php

declare(strict_types=1);

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Pipeline\ValidationContext;

covers(ValidationContext::class);

it('starts empty', function () {
    $context = new ValidationContext('<a/>');

    expect($context->bytes)->toBe('<a/>')
        ->and($context->findings())->toBe([])
        ->and($context->skipped())->toBe([])
        ->and($context->isUnsupported())->toBeFalse()
        ->and($context->document)->toBeNull()
        ->and($context->invoice)->toBeNull()
        ->and($context->type)->toBeNull();
});

it('collects findings and skipped checks in order', function () {
    $factory = new FindingFactory(RuleCatalog::fromDirectory(rulesPath()));
    $context = new ValidationContext('<a/>');
    $first = $factory->create('XML-EMPTY');
    $second = $factory->create('SEC-DOCTYPE');
    $skipped = $factory->skipped('KSEF-DUPLICATE');

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
