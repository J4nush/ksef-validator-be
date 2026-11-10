<?php

declare(strict_types=1);

use KsefFa3\Rule\AttachmentBatchOnlyRule;

covers(AttachmentBatchOnlyRule::class);

it('warns once about an attachment at its line', function () {
    $xml = <<<'XML'
        <Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">
          <Naglowek/>
          <Zalacznik/>
        </Faktura>
        XML;

    expect(violationSummary(violationsOf(new AttachmentBatchOnlyRule, $xml)))
        ->toBe([['TECH-ATTACHMENT-BATCH-ONLY', 'Zalacznik', 3]]);
});

it('ignores a document without an attachment', function () {
    expect(violationsOf(new AttachmentBatchOnlyRule, fa3Xml('<Naglowek/>')))->toBe([]);
});

it('ignores an attachment outside the invoice root', function () {
    expect(violationsOf(new AttachmentBatchOnlyRule, fa3Xml('<Fa><Zalacznik/></Fa>')))->toBe([]);
});
