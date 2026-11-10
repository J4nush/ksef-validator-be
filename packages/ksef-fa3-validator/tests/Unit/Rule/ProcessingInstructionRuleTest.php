<?php

declare(strict_types=1);

use KsefFa3\Rule\ProcessingInstructionRule;

covers(ProcessingInstructionRule::class);

it('reports processing instructions once with their count and the first line', function () {
    $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <?foo bar?>
        <Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">
          <Naglowek>
            <SystemInfo><?foo bar?>Program</SystemInfo>
          </Naglowek>
        </Faktura>
        XML;
    $violations = violationsOf(new ProcessingInstructionRule, $xml);

    expect(violationSummary($violations))->toBe([['TECH-PROCESSING-INSTRUCTION', null, 2]])
        ->and($violations[0]->params)->toBe(['count' => 2]);
});

it('does not treat the XML declaration as a processing instruction', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".fa3Xml('<Naglowek/>');

    expect(violationsOf(new ProcessingInstructionRule, $xml))->toBe([]);
});

it('ignores a document without processing instructions', function () {
    expect(violationsOf(new ProcessingInstructionRule, fa3Xml('<Naglowek/>')))->toBe([]);
});

it('counts a single processing instruction', function () {
    $violations = violationsOf(new ProcessingInstructionRule, fa3Xml("<Naglowek/>\n<?foo?>"));

    expect(violationSummary($violations))->toBe([['TECH-PROCESSING-INSTRUCTION', null, 2]])
        ->and($violations[0]->params)->toBe(['count' => 1]);
});
