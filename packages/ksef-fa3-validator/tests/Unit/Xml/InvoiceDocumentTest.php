<?php

declare(strict_types=1);

use KsefFa3\Xml\InvoiceDocument;

covers(InvoiceDocument::class);

const INVOICE_WITH_SUBJECTS = <<<'XML'
<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">
  <Naglowek>
    <WariantFormularza>3</WariantFormularza>
  </Naglowek>
  <Podmiot1>
    <DaneIdentyfikacyjne>
      <NIP>1111111111</NIP>
    </DaneIdentyfikacyjne>
  </Podmiot1>
  <Podmiot2>
    <DaneIdentyfikacyjne>
      <NIP>2222222222</NIP>
    </DaneIdentyfikacyjne>
  </Podmiot2>
  <Podmiot3>
    <DaneIdentyfikacyjne>
      <NIP>1111111111</NIP>
    </DaneIdentyfikacyjne>
  </Podmiot3>
  <Podmiot3>
    <DaneIdentyfikacyjne>
      <NIP>1111111112</NIP>
    </DaneIdentyfikacyjne>
  </Podmiot3>
  <Podmiot3>
    <DaneIdentyfikacyjne>
      <NIP>2222222222</NIP>
    </DaneIdentyfikacyjne>
  </Podmiot3>
</Faktura>
XML;

function invoiceDocument(string $xml): InvoiceDocument
{
    $document = new DOMDocument;
    $document->loadXML($xml);

    return new InvoiceDocument($document);
}

function prefixed(string $xml, string $prefix): string
{
    return (string) preg_replace(
        ['#<(/?)(?=[A-Z])#', '#xmlns="#'],
        ['<$1'.$prefix.':', 'xmlns:'.$prefix.'="'],
        $xml,
    );
}

it('finds elements through the registered fa prefix', function () {
    $invoice = invoiceDocument(INVOICE_WITH_SUBJECTS);
    $nips = $invoice->nodes('/fa:Faktura/*/fa:DaneIdentyfikacyjne/fa:NIP');

    expect($nips)->toHaveCount(5)
        ->and(array_map(fn (DOMElement $nip): string => $nip->textContent, $nips))
        ->toBe(['1111111111', '2222222222', '1111111111', '1111111112', '2222222222'])
        ->and($invoice->first('/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NIP')?->textContent)->toBe('2222222222');
});

it('returns only elements from a query', function () {
    $invoice = invoiceDocument(INVOICE_WITH_SUBJECTS);

    expect($invoice->nodes('/fa:Faktura/fa:Naglowek/fa:WariantFormularza/text()'))->toBe([])
        ->and($invoice->first('/fa:Faktura/fa:Naglowek/fa:WariantFormularza/text()'))->toBeNull()
        ->and($invoice->nodes('//fa:Brak'))->toBe([])
        ->and($invoice->first('//fa:Brak'))->toBeNull();
});

it('ignores elements outside the FA(3) namespace', function () {
    $invoice = invoiceDocument('<Faktura><Naglowek/></Faktura>');

    expect($invoice->nodes('//fa:Naglowek'))->toBe([]);
});

it('builds field paths from below the root', function (string $prefix) {
    $xml = $prefix === '' ? INVOICE_WITH_SUBJECTS : prefixed(INVOICE_WITH_SUBJECTS, $prefix);
    $invoice = invoiceDocument($xml);
    $paths = array_map(
        fn (DOMElement $nip): string => $invoice->fieldPath($nip),
        $invoice->nodes('//fa:NIP'),
    );

    expect($paths)->toBe([
        'Podmiot1/DaneIdentyfikacyjne/NIP',
        'Podmiot2/DaneIdentyfikacyjne/NIP',
        'Podmiot3[1]/DaneIdentyfikacyjne/NIP',
        'Podmiot3[2]/DaneIdentyfikacyjne/NIP',
        'Podmiot3[3]/DaneIdentyfikacyjne/NIP',
    ]);
})->with([
    'default namespace' => '',
    'fa prefix' => 'fa',
    'other prefix' => 'tns',
]);

it('indexes only siblings with the same name and namespace', function () {
    $invoice = invoiceDocument(<<<'XML'
        <Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/" xmlns:x="urn:other">
          <Fa>
            <P_1>2026-11-10</P_1>
            <FaWiersz><NrWierszaFa>1</NrWierszaFa></FaWiersz>
            <x:FaWiersz/>
            <Adnotacje/>
            <FaWiersz><NrWierszaFa>2</NrWierszaFa></FaWiersz>
          </Fa>
        </Faktura>
        XML);
    $paths = array_map(
        fn (DOMElement $element): string => $invoice->fieldPath($element),
        [...$invoice->nodes('//fa:NrWierszaFa'), ...$invoice->nodes('//fa:P_1')],
    );

    expect($paths)->toBe(['Fa/FaWiersz[1]/NrWierszaFa', 'Fa/FaWiersz[2]/NrWierszaFa', 'Fa/P_1']);
});

it('gives the root an empty path and its children their own name', function () {
    $invoice = invoiceDocument(INVOICE_WITH_SUBJECTS);

    expect($invoice->fieldPath($invoice->first('/fa:Faktura') ?? throw new LogicException))->toBe('')
        ->and($invoice->fieldPath($invoice->first('/fa:Faktura/fa:Naglowek') ?? throw new LogicException))->toBe('Naglowek');
});

it('detects an attachment only directly under the root', function (string $xml, bool $expected) {
    expect(invoiceDocument($xml)->hasAttachment())->toBe($expected);
})->with([
    'attachment' => ['<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Zalacznik/></Faktura>', true],
    'prefixed attachment' => ['<fa:Faktura xmlns:fa="http://crd.gov.pl/wzor/2025/06/25/13775/"><fa:Zalacznik/></fa:Faktura>', true],
    'no attachment' => ['<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Fa/></Faktura>', false],
    'nested deeper' => ['<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Fa><Zalacznik/></Fa></Faktura>', false],
    'other namespace' => ['<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Zalacznik xmlns="urn:other"/></Faktura>', false],
]);

it('exposes the wrapped document', function () {
    $document = new DOMDocument;
    $document->loadXML('<a/>');

    expect((new InvoiceDocument($document))->document)->toBe($document);
});

it('gives the same paths whatever order the elements are asked for', function () {
    $invoice = invoiceDocument(INVOICE_WITH_SUBJECTS);
    $nips = array_reverse($invoice->nodes('//fa:NIP'));
    $paths = array_map(fn (DOMElement $nip): string => $invoice->fieldPath($nip), $nips);

    expect($paths)->toBe([
        'Podmiot3[3]/DaneIdentyfikacyjne/NIP',
        'Podmiot3[2]/DaneIdentyfikacyjne/NIP',
        'Podmiot3[1]/DaneIdentyfikacyjne/NIP',
        'Podmiot2/DaneIdentyfikacyjne/NIP',
        'Podmiot1/DaneIdentyfikacyjne/NIP',
    ])->and(array_map(fn (DOMElement $nip): string => $invoice->fieldPath($nip), $nips))->toBe($paths);
});

it('keeps positions apart for documents sharing element names', function () {
    $first = invoiceDocument(fa3Xml('<Fa><FaWiersz/><FaWiersz/></Fa>'));
    $second = invoiceDocument(fa3Xml('<Fa><FaWiersz/></Fa>'));

    expect($first->fieldPath($first->nodes('//fa:FaWiersz')[1]))->toBe('Fa/FaWiersz[2]')
        ->and($second->fieldPath($second->nodes('//fa:FaWiersz')[0]))->toBe('Fa/FaWiersz');
});

it('finds elements by line, name and namespace', function () {
    $document = ruleDocument(<<<'XML'
<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"><Fa><P_2>A</P_2><P_2>B</P_2></Fa>
  <Fa><P_2 xmlns="">C</P_2><P_2 xmlns="urn:x">D</P_2></Fa>
</Faktura>
XML);

    $texts = fn (array $elements): array => array_map(fn (mixed $element): string => $element instanceof DOMElement ? $element->textContent : '', $elements);

    expect($texts($document->elementsAt(1, 'P_2', InvoiceDocument::FA3_NAMESPACE)))->toBe(['A', 'B'])
        ->and($texts($document->elementsAt(2, 'P_2', null)))->toBe(['C'])
        ->and($texts($document->elementsAt(2, 'P_2', 'urn:x')))->toBe(['D'])
        ->and($document->elementsAt(2, 'P_2', InvoiceDocument::FA3_NAMESPACE))->toBe([])
        ->and($document->elementsAt(3, 'P_2', InvoiceDocument::FA3_NAMESPACE))->toBe([])
        ->and($document->elementsAt(1, 'Faktura', InvoiceDocument::FA3_NAMESPACE))->toHaveCount(1);
});

it('gives the root its own name as the field path', function () {
    $document = ruleDocument(INVOICE_WITH_SUBJECTS);
    $root = $document->first('/fa:Faktura');
    assert($root !== null);

    expect($document->fieldPath($root))->toBe('')
        ->and($document->fieldOf($root))->toBe('Faktura')
        ->and($document->fieldOf($document->nodes('//fa:Podmiot3/fa:DaneIdentyfikacyjne/fa:NIP')[1]))->toBe('Podmiot3[2]/DaneIdentyfikacyjne/NIP');
});

it('builds the path of an element without a namespace cleanly', function () {
    $document = ruleDocument(fa3Xml('<Fa><P_2 xmlns="">A</P_2><P_2>B</P_2></Fa>'));
    $element = $document->nodes('//*[local-name()="P_2"]')[0];
    set_error_handler(function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        expect($document->fieldPath($element))->toBe('Fa/P_2');
    } finally {
        restore_error_handler();
    }
});

it('does not mix up elements whose line, namespace and name run together', function () {
    $document = ruleDocument('<r xmlns="urn:x" xmlns:a="urn:a" xmlns:b="urn:ab" xmlns:y="urn:x1"><a:bX/><b:X/><y:P/><P1/>'.str_repeat("\n", 10).'<P/></r>');

    expect($document->elementsAt(1, 'X', 'urn:ab'))->toHaveCount(1)
        ->and($document->elementsAt(1, 'bX', 'urn:a'))->toHaveCount(1)
        ->and($document->elementsAt(11, 'P', 'urn:x'))->toHaveCount(1)
        ->and($document->elementsAt(1, 'P', 'urn:x1'))->toHaveCount(1);
});
