<?php

declare(strict_types=1);

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Fa3Validator;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Report;
use KsefFa3\Report\Result;
use KsefFa3\Report\SkippedCheck;
use KsefFa3\Stage\RecognitionStage;
use KsefFa3\Xml\InvoiceDocument;

covers(Fa3Validator::class, RecognitionStage::class, InvoiceDocument::class);

/**
 * @param  array{namespace?: string, root?: string, code?: string, system?: string, version?: string, variant?: string}  $overrides
 */
function invoiceHeader(array $overrides = []): string
{
    $header = $overrides + [
        'namespace' => 'http://crd.gov.pl/wzor/2025/06/25/13775/',
        'root' => 'Faktura',
        'code' => 'FA',
        'system' => 'FA (3)',
        'version' => '1-0E',
        'variant' => '3',
    ];

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <{$header['root']} xmlns="{$header['namespace']}">
          <Naglowek>
            <KodFormularza kodSystemowy="{$header['system']}" wersjaSchemy="{$header['version']}">{$header['code']}</KodFormularza>
            <WariantFormularza>{$header['variant']}</WariantFormularza>
          </Naglowek>
        </{$header['root']}>
        XML;
}

function recognitionStage(): RecognitionStage
{
    return new RecognitionStage(new FindingFactory(RuleCatalog::fromDirectory(rulesPath())));
}

function parsedContext(string $xml): ValidationContext
{
    $context = new ValidationContext($xml);
    $context->document = new DOMDocument;
    $context->document->loadXML($xml);

    return $context;
}

/**
 * @return list<string>
 */
function skippedIds(Report $report): array
{
    return array_map(fn (SkippedCheck $check): string => $check->id, $report->skipped);
}

it('recognizes an FA(3) header', function (string $xml) {
    $report = validateBytes($xml);

    expect($report->result())->not->toBe(Result::Unsupported)
        ->and(findingIds($report))->not->toContain('DOC-UNSUPPORTED')
        ->and($report->document->type)->toBe('FA')
        ->and($report->document->variant)->toBe('3')
        ->and($report->document->schema)->toBe('1-0E')
        ->and(skippedIds($report))->toBe(['KSEF-DUPLICATE'])
        ->and($report->skipped[0]->message)->toContain('jest już w KSeF');
})->with([
    'default namespace' => invoiceHeader(),
    'whitespace around the code and the variant' => invoiceHeader(['code' => "\n FA ", 'variant' => "\n      3\n    "]),
    'fa prefix' => <<<'XML'
        <fa:Faktura xmlns:fa="http://crd.gov.pl/wzor/2025/06/25/13775/">
          <fa:Naglowek>
            <fa:KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</fa:KodFormularza>
            <fa:WariantFormularza>3</fa:WariantFormularza>
          </fa:Naglowek>
        </fa:Faktura>
        XML,
    'other prefix' => <<<'XML'
        <tns:Faktura xmlns:tns="http://crd.gov.pl/wzor/2025/06/25/13775/" xmlns:etd="http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/">
          <tns:Naglowek>
            <tns:KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</tns:KodFormularza>
            <tns:WariantFormularza>3</tns:WariantFormularza>
          </tns:Naglowek>
        </tns:Faktura>
        XML,
]);

it('leaves missing header values to the schema check', function (string $xml) {
    $report = validateBytes($xml);

    expect($report->result())->not->toBe(Result::Unsupported)
        ->and($report->document->type)->toBe('FA')
        ->and(skippedIds($report))->toBe(['KSEF-DUPLICATE']);
})->with([
    'no header' => '<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"/>',
    'no variant' => '<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Naglowek><KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</KodFormularza></Naglowek></Faktura>',
    'no form code' => '<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Naglowek><WariantFormularza>3</WariantFormularza></Naglowek></Faktura>',
    'no attributes' => '<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Naglowek><KodFormularza>FA</KodFormularza><WariantFormularza>3</WariantFormularza></Naglowek></Faktura>',
]);

it('stops on an FA(2) invoice', function (string $xml) {
    $report = validateBytes($xml);

    expect($report->result())->toBe(Result::Unsupported)
        ->and(findingIds($report))->toBe(['DOC-FA2-UNSUPPORTED'])
        ->and($report->skipped)->toBe([])
        ->and($report->document->type)->toBeNull()
        ->and($report->document->variant)->toBeNull()
        ->and($report->document->schema)->toBeNull();
})->with([
    'FA(2) header' => invoiceHeader(['namespace' => RecognitionStage::FA2_NAMESPACE, 'system' => 'FA (2)', 'variant' => '2']),
    'prefixed' => '<fa:Faktura xmlns:fa="http://crd.gov.pl/wzor/2023/06/29/12648/"/>',
]);

it('stops on a document that is not an FA(3) invoice', function (string $xml) {
    $report = validateBytes($xml);

    expect($report->result())->toBe(Result::Unsupported)
        ->and(findingIds($report))->toBe(['DOC-UNSUPPORTED'])
        ->and($report->findings[0]->field)->toBeNull()
        ->and($report->skipped)->toBe([])
        ->and($report->document->type)->toBeNull()
        ->and($report->document->variant)->toBeNull()
        ->and($report->document->schema)->toBeNull();
})->with([
    'Invoice root' => invoiceHeader(['root' => 'Invoice']),
    'UBL invoice' => '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>',
    'foreign namespace' => invoiceHeader(['namespace' => 'urn:example:faktura']),
    'no namespace' => str_replace(' xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"', '', invoiceHeader()),
    'FA(3) namespace on a child only' => '<Faktura><Naglowek xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"/></Faktura>',
    'other root in the FA(3) namespace' => '<Dokument xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"/>',
    'schema version 1-1E' => invoiceHeader(['version' => '1-1E']),
    'system code FA (2)' => invoiceHeader(['system' => 'FA (2)']),
    'system code without the space' => invoiceHeader(['system' => 'FA(3)']),
    'variant 2' => invoiceHeader(['variant' => '2']),
    'variant 4' => invoiceHeader(['variant' => '4']),
    'form code KOR' => invoiceHeader(['code' => 'KOR']),
    'empty variant' => invoiceHeader(['variant' => '']),
    'arbitrary element' => '<a/>',
]);

it('wraps the document for the rules after recognition', function () {
    $context = parsedContext(invoiceHeader());

    expect(recognitionStage()->process($context))->toBe(StageOutcome::Continue)
        ->and($context->invoice)->toBeInstanceOf(InvoiceDocument::class)
        ->and($context->invoice?->document)->toBe($context->document)
        ->and($context->type)->toBe('FA')
        ->and($context->variant)->toBe('3')
        ->and($context->schema)->toBe('1-0E')
        ->and($context->isUnsupported())->toBeFalse();
});

it('stops without wrapping an unsupported document', function (string $xml) {
    $context = parsedContext($xml);

    expect(recognitionStage()->process($context))->toBe(StageOutcome::Stop)
        ->and($context->invoice)->toBeNull()
        ->and($context->type)->toBeNull()
        ->and($context->isUnsupported())->toBeTrue();
})->with([
    'FA(2)' => invoiceHeader(['namespace' => RecognitionStage::FA2_NAMESPACE]),
    'variant 2' => invoiceHeader(['variant' => '2']),
]);

it('stops when there is no parsed document', function () {
    $context = new ValidationContext('<a/>');

    expect(recognitionStage()->process($context))->toBe(StageOutcome::Stop)
        ->and($context->isUnsupported())->toBeTrue()
        ->and(array_map(fn ($finding): string => $finding->id, $context->findings()))->toBe(['DOC-UNSUPPORTED']);
});

it('stops on a document without a root element', function () {
    $context = new ValidationContext('<a/>');
    $context->document = new DOMDocument;

    expect(recognitionStage()->process($context))->toBe(StageOutcome::Stop)
        ->and($context->invoice)->toBeNull()
        ->and(array_map(fn ($finding): string => $finding->id, $context->findings()))->toBe(['DOC-UNSUPPORTED']);
});
