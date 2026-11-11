<?php

declare(strict_types=1);

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Schema\LocalSchemaRepository;
use KsefFa3\Xml\InvoiceDocument;
use KsefFa3\Xml\LibxmlGuard;
use KsefFa3\Xsd\XsdError;
use KsefFa3\Xsd\XsdErrorClassifier;

covers(XsdErrorClassifier::class, XsdError::class);

function xsdClassifier(): XsdErrorClassifier
{
    return XsdErrorClassifier::fromDirectories(dirname(__DIR__, 3).'/resources/fields', schemaPath());
}

function invoiceOf(string $bytes): InvoiceDocument
{
    $document = new DOMDocument;
    $document->loadXML($bytes, LIBXML_NONET | LIBXML_BIGLINES);

    return new InvoiceDocument($document);
}

/**
 * @return list<LibXMLError>
 */
function schemaErrorsOf(InvoiceDocument $invoice): array
{
    $repository = new LocalSchemaRepository;
    [, $errors] = LibxmlGuard::run(
        fn (): bool => $invoice->document->schemaValidateSource($repository->fa3SchemaSource(), LIBXML_NONET),
        $repository->schemaDirectory(),
    );

    return $errors;
}

function mutated(string $search, string $replace, string $file = 'valid-minimal.xml'): string
{
    $original = fixtureBytes($file);
    $bytes = str_replace($search, $replace, $original);
    assert($bytes !== $original, "Nothing to replace for {$search}.");

    return $bytes;
}

function classifyFirst(string $bytes): XsdError
{
    $invoice = invoiceOf($bytes);
    $errors = schemaErrorsOf($invoice);
    assert($errors !== [], 'The mutated invoice is valid.');

    return xsdClassifier()->classify($errors[0], $invoice);
}

function faSection(): string
{
    $bytes = fixtureBytes('valid-minimal.xml');
    $start = (int) strpos($bytes, '  <Fa>');

    return substr($bytes, $start, (int) strpos($bytes, '</Fa>') + strlen("</Fa>\n") - $start);
}

function minimalRow(): string
{
    $bytes = fixtureBytes('valid-minimal.xml');
    $start = (int) strpos($bytes, '<FaWiersz>');

    return substr($bytes, $start, (int) strpos($bytes, '</FaWiersz>') + strlen('</FaWiersz>') - $start);
}

const ADDRESS_END = "<AdresL2>00-000 Przykładowo</AdresL2>\n    </Adres>\n  </Podmiot1>";

it('classifies a real schema error', function (string $search, string $replace, string $id, ?string $field, int $line, array $params, array $hints) {
    $error = classifyFirst(mutated($search, $replace));

    expect($error->id)->toBe($id)
        ->and($error->field)->toBe($field)
        ->and($error->line)->toBe($line)
        ->and($error->hints)->toBe($hints)
        ->and(array_intersect_key($error->params, $params))->toEqual($params);
})->with([
    'missing in the middle of a sequence' => ['<P_2>FV/1/02/2026</P_2>', '', 'XSD-MISSING-ELEMENT', 'Fa/P_2', 36, ['field' => 'P_2', 'label' => 'numer faktury', 'parent' => 'dane faktury'], ['P_2']],
    'missing as the only expected element' => ['<NrWierszaFa>1</NrWierszaFa>', '', 'XSD-MISSING-ELEMENT', 'Fa/FaWiersz/NrWierszaFa', 59, ['label' => 'numer wiersza', 'parent' => 'wiersz faktury'], ['NrWierszaFa']],
    'missing behind a truncated list' => ['<P_15>123.00</P_15>', '', 'XSD-MISSING-ELEMENT', 'Fa/P_15', 40, ['label' => 'kwota należności ogółem'], ['P_15']],
    'missing child at the end of a parent' => ['<AdresL1>ul. Fikcyjna 2</AdresL1>', '', 'XSD-MISSING-ELEMENT', 'Podmiot2/Adres/AdresL1', 27, ['parent' => 'adres'], ['AdresL1']],
    'empty parent' => ['<P_19N>1</P_19N>', '', 'XSD-MISSING-ELEMENT', 'Fa/Adnotacje/Zwolnienie/P_19', 45, ['parent' => 'zwolnienie z podatku'], ['P_19']],
    'missing choice' => ["<NIP>2222222222</NIP>\n      <Nazwa>Przykładowy Nabywca", '<Nazwa>Przykładowy Nabywca', 'XSD-MISSING-ELEMENT', 'Podmiot2/DaneIdentyfikacyjne/NIP', 21, ['label' => 'NIP nabywcy'], ['NIP']],
    'missing section under the root' => [faSection(), '', 'XSD-MISSING-ELEMENT', 'Fa', 2, ['field' => 'Fa', 'label' => 'dane faktury', 'parent' => 'faktura'], ['Fa']],
    'tax without its net amount' => ['<P_13_1>100.00</P_13_1>', '', 'XSD-MISSING-ELEMENT', 'Fa/P_13_1', 38, [], ['P_13_1']],
    'net amount without its tax' => ['<P_14_1>23.00</P_14_1>', '', 'XSD-MISSING-ELEMENT', 'Fa/P_14_1', 39, ['label' => 'podatek ze stawką 23% lub 22%'], ['P_14_1']],
    'missing header field' => ["\n    <DataWytworzeniaFa>2026-02-15T09:30:00Z</DataWytworzeniaFa>", "\n", 'XSD-MISSING-ELEMENT', 'Naglowek/DataWytworzeniaFa', 3, ['parent' => 'nagłówek'], ['DataWytworzeniaFa']],
    'order' => ["<P_2>FV/1/02/2026</P_2>\n    <P_6>2026-02-15</P_6>", "<P_6>2026-02-15</P_6>\n    <P_2>FV/1/02/2026</P_2>", 'XSD-ELEMENT-ORDER', 'Fa/P_6', 35, ['field' => 'P_6', 'label' => 'data dostawy lub wykonania usługi', 'expected' => 'numer faktury'], []],
    'repeated' => ["<P_2>FV/1/02/2026</P_2>\n", "<P_2>FV/1/02/2026</P_2>\n    <P_2>FV/2/02/2026</P_2>\n", 'XSD-ELEMENT-REPEATED', 'Fa/P_2[2]', 36, ['field' => 'P_2', 'label' => 'numer faktury'], []],
    'repeated on one line' => ['<P_2>FV/1/02/2026</P_2>', '<P_2>FV/1/02/2026</P_2><P_2>FV/2/02/2026</P_2>', 'XSD-ELEMENT-REPEATED', 'Fa/P_2[2]', 35, [], []],
    'unknown element' => ['<P_6>', '<Uwagi>tekst</Uwagi><P_6>', 'XSD-UNEXPECTED-ELEMENT', 'Fa/Uwagi', 36, ['field' => 'Uwagi', 'label' => 'Uwagi'], []],
    'known element in a wrong section' => ['<P_6>', '<P_12>23</P_12><P_6>', 'XSD-UNEXPECTED-ELEMENT', 'Fa/P_12', 36, ['field' => 'P_12'], []],
    'element without namespace' => ['<P_2>FV/1/02/2026</P_2>', '<P_2 xmlns="">FV/1/02/2026</P_2>', 'XSD-WRONG-NAMESPACE', 'Fa/P_2', 35, ['field' => 'P_2'], ['no-namespace']],
    'element in a foreign namespace' => ['<P_2>FV/1/02/2026</P_2>', '<P_2 xmlns="urn:x">FV/1/02/2026</P_2>', 'XSD-WRONG-NAMESPACE', 'Fa/P_2', 35, ['field' => 'P_2'], []],
    'unknown element in a foreign namespace' => ['<P_6>', '<Uwagi xmlns="urn:x">tekst</Uwagi><P_6>', 'XSD-UNEXPECTED-ELEMENT', 'Fa/Uwagi', 36, [], []],
    'amount with a comma' => ['<P_15>123.00</P_15>', '<P_15>123,00</P_15>', 'XSD-DECIMAL-FORMAT', 'Fa/P_15', 39, ['value' => '123,00', 'label' => 'kwota należności ogółem'], ['comma', 'P_15']],
    'amount with a space' => ['<P_13_1>100.00</P_13_1>', '<P_13_1>1 100.00</P_13_1>', 'XSD-DECIMAL-FORMAT', 'Fa/P_13_1', 37, ['value' => '1 100.00'], ['space', 'P_13_1']],
    'amount as text' => ['<P_15>123.00</P_15>', '<P_15>sto</P_15>', 'XSD-DECIMAL-FORMAT', 'Fa/P_15', 39, ['value' => 'sto'], ['P_15']],
    'amount in exponent notation' => ['<P_15>123.00</P_15>', '<P_15>1.23E2</P_15>', 'XSD-DECIMAL-FORMAT', 'Fa/P_15', 39, [], ['P_15']],
    'amount with 17 digits' => ['<P_15>123.00</P_15>', '<P_15>12345678901234567.00</P_15>', 'XSD-DECIMAL-FORMAT', 'Fa/P_15', 39, [], ['P_15']],
    'amount with a leading zero' => ['<P_15>123.00</P_15>', '<P_15>0123.00</P_15>', 'XSD-DECIMAL-FORMAT', 'Fa/P_15', 39, [], ['leading-zero', 'P_15']],
    'negative amount with a leading zero' => ['<P_15>123.00</P_15>', '<P_15>-0123.00</P_15>', 'XSD-DECIMAL-FORMAT', 'Fa/P_15', 39, [], ['leading-zero', 'P_15']],
    'amount with a plus' => ['<P_15>123.00</P_15>', '<P_15>+123.00</P_15>', 'XSD-DECIMAL-FORMAT', 'Fa/P_15', 39, [], ['plus', 'P_15']],
    'amount ending with a dot' => ['<P_15>123.00</P_15>', '<P_15>123.</P_15>', 'XSD-DECIMAL-FORMAT', 'Fa/P_15', 39, [], ['trailing-dot', 'P_15']],
    'quantity with a comma' => ['<P_8B>1</P_8B>', '<P_8B>1,5</P_8B>', 'XSD-DECIMAL-FORMAT', 'Fa/FaWiersz/P_8B', 61, [], ['comma', 'P_8B']],
    'amount with three decimals' => ['<P_14_1>23.00</P_14_1>', '<P_14_1>23.001</P_14_1>', 'XSD-DECIMAL-PRECISION', 'Fa/P_14_1', 38, ['value' => '23.001', 'max' => '2'], ['P_14_1', 'max-2']],
    'price with nine decimals' => ['<P_9A>100.00</P_9A>', '<P_9A>100.123456789</P_9A>', 'XSD-DECIMAL-PRECISION', 'Fa/FaWiersz/P_9A', 62, ['max' => '8'], ['P_9A', 'max-8']],
    'quantity with seven decimals' => ['<P_8B>1</P_8B>', '<P_8B>1.1234567</P_8B>', 'XSD-DECIMAL-PRECISION', 'Fa/FaWiersz/P_8B', 61, ['max' => '6'], ['P_8B', 'max-6']],
    'empty amount' => ['<P_15>123.00</P_15>', '<P_15/>', 'XSD-EMPTY', 'Fa/P_15', 39, ['label' => 'kwota należności ogółem'], ['P_15']],
    'polish date' => ['<P_1>2026-02-15</P_1>', '<P_1>15.02.2026</P_1>', 'XSD-DATE-FORMAT', 'Fa/P_1', 34, ['value' => '15.02.2026', 'label' => 'data wystawienia'], ['P_1']],
    'nonexistent day' => ['<P_6>2026-02-15</P_6>', '<P_6>2026-02-30</P_6>', 'XSD-DATE-FORMAT', 'Fa/P_6', 36, ['value' => '2026-02-30'], ['nonexistent', 'P_6']],
    'nonexistent month' => ['<P_6>2026-02-15</P_6>', '<P_6>2026-13-01</P_6>', 'XSD-DATE-FORMAT', 'Fa/P_6', 36, [], ['nonexistent', 'P_6']],
    'date without leading zeros' => ['<P_6>2026-02-15</P_6>', '<P_6>2026-2-5</P_6>', 'XSD-DATE-FORMAT', 'Fa/P_6', 36, [], ['P_6']],
    'issue date with time' => ['<P_1>2026-02-15</P_1>', '<P_1>2026-02-15T10:00:00</P_1>', 'XSD-DATE-FORMAT', 'Fa/P_1', 34, [], ['P_1']],
    'creation date without time' => ['2026-02-15T09:30:00Z', '2026-02-15', 'XSD-DATE-FORMAT', 'Naglowek/DataWytworzeniaFa', 6, ['value' => '2026-02-15'], ['DataWytworzeniaFa']],
    'creation date with a space' => ['2026-02-15T09:30:00Z', '2026-02-15 09:30', 'XSD-DATE-FORMAT', 'Naglowek/DataWytworzeniaFa', 6, [], ['DataWytworzeniaFa']],
    'empty date' => ['<P_6>2026-02-15</P_6>', '<P_6></P_6>', 'XSD-EMPTY', 'Fa/P_6', 36, [], ['P_6']],
    'issue date too early' => ['<P_1>2026-02-15</P_1>', '<P_1>2005-01-01</P_1>', 'XSD-DATE-RANGE', 'Fa/P_1', 34, ['value' => '2005-01-01', 'limit' => '2006-01-01'], ['P_1', 'too-early']],
    'issue date too late' => ['<P_1>2026-02-15</P_1>', '<P_1>2051-01-01</P_1>', 'XSD-DATE-RANGE', 'Fa/P_1', 34, ['value' => '2051-01-01'], ['P_1', 'too-late']],
    'creation date too early' => ['2026-02-15T09:30:00Z', '2025-08-31T10:00:00Z', 'XSD-DATE-RANGE', 'Naglowek/DataWytworzeniaFa', 6, ['limit' => '2025-09-01T00:00:00Z'], ['DataWytworzeniaFa', 'too-early']],
    'invoice number too long' => ['<P_2>FV/1/02/2026</P_2>', '<P_2>'.str_repeat('A', 257).'</P_2>', 'XSD-MAX-LENGTH', 'Fa/P_2', 35, ['length' => '257', 'max' => '256'], ['P_2']],
    'description too long' => ['<P_7>Przykładowa usługa</P_7>', '<P_7>'.str_repeat('a', 513).'</P_7>', 'XSD-MAX-LENGTH', 'Fa/FaWiersz/P_7', 59, ['length' => '513', 'max' => '512'], ['P_7']],
    'empty invoice number' => ['<P_2>FV/1/02/2026</P_2>', '<P_2></P_2>', 'XSD-EMPTY', 'Fa/P_2', 35, ['label' => 'numer faktury'], ['P_2']],
    'name made of spaces' => ['<Nazwa>Przykładowy Sprzedawca Sp. z o.o.</Nazwa>', '<Nazwa>   </Nazwa>', 'XSD-EMPTY', 'Podmiot1/DaneIdentyfikacyjne/Nazwa', 11, ['label' => 'nazwa sprzedawcy'], ['Nazwa']],
    'empty enumeration' => ['<P_12>23</P_12>', '<P_12></P_12>', 'XSD-EMPTY', 'Fa/FaWiersz/P_12', 64, [], ['P_12']],
    'empty NIP' => ['<NIP>1111111111</NIP>', '<NIP></NIP>', 'XSD-EMPTY', 'Podmiot1/DaneIdentyfikacyjne/NIP', 10, ['label' => 'NIP sprzedawcy'], ['NIP']],
    'empty line number' => ['<NrWierszaFa>1</NrWierszaFa>', '<NrWierszaFa></NrWierszaFa>', 'XSD-EMPTY', 'Fa/FaWiersz/NrWierszaFa', 58, [], ['NrWierszaFa']],
    'short bank account' => ['</FaWiersz>', '</FaWiersz><Platnosc><RachunekBankowy><NrRB>12</NrRB></RachunekBankowy></Platnosc>', 'XSD-MIN-LENGTH', 'Fa/Platnosc/RachunekBankowy/NrRB', 65, ['length' => '2', 'min' => '10'], ['NrRB']],
    'VAT rate with percent' => ['<P_12>23</P_12>', '<P_12>23%</P_12>', 'XSD-ENUM', 'Fa/FaWiersz/P_12', 64, ['value' => '23%', 'allowed' => '23, 22, 8, 7, 5, 4, 3, 0 KR, 0 WDT, 0 EX, zw, oo, np I, np II', 'label' => 'stawka VAT'], ['P_12']],
    'VAT rate zero' => ['<P_12>23</P_12>', '<P_12>0</P_12>', 'XSD-ENUM', 'Fa/FaWiersz/P_12', 64, ['value' => '0'], ['P_12']],
    'currency in lowercase' => ['<KodWaluty>PLN', '<KodWaluty>pln', 'XSD-ENUM', 'Fa/KodWaluty', 33, ['value' => 'pln'], ['KodWaluty', 'long-list']],
    'unknown country' => ['<KodKraju>PL</KodKraju>', '<KodKraju>XX</KodKraju>', 'XSD-ENUM', 'Podmiot1/Adres/KodKraju', 14, ['value' => 'XX'], ['KodKraju', 'long-list']],
    'unknown invoice type' => ['<RodzajFaktury>VAT', '<RodzajFaktury>FAKTURA', 'XSD-ENUM', 'Fa/RodzajFaktury', 56, ['allowed' => 'VAT, KOR, ZAL, ROZ, UPR, KOR_ZAL, KOR_ROZ'], ['RodzajFaktury']],
    'flag out of 1 and 2' => ['<P_16>2</P_16>', '<P_16>3</P_16>', 'XSD-ENUM', 'Fa/Adnotacje/P_16', 41, ['allowed' => '1, 2'], ['P_16']],
    'local government flag' => ['<JST>2</JST>', '<JST>0</JST>', 'XSD-ENUM', 'Podmiot2/JST', 29, [], ['JST']],
    'single choice flag' => ['<P_19N>1</P_19N>', '<P_19N>2</P_19N>', 'XSD-ENUM', 'Fa/Adnotacje/Zwolnienie/P_19N', 46, ['allowed' => '1'], ['P_19N']],
    'payment form' => ['</FaWiersz>', '</FaWiersz><Platnosc><FormaPlatnosci>9</FormaPlatnosci></Platnosc>', 'XSD-ENUM', 'Fa/Platnosc/FormaPlatnosci', 65, ['value' => '9'], ['FormaPlatnosci']],
    'form variant' => ['<WariantFormularza>3', '<WariantFormularza>2', 'XSD-ENUM', 'Naglowek/WariantFormularza', 5, ['allowed' => '3'], ['WariantFormularza']],
    'form code' => ['FA</KodFormularza>', 'FV</KodFormularza>', 'XSD-ENUM', 'Naglowek/KodFormularza', 4, ['allowed' => 'FA'], ['KodFormularza']],
    'NIP with letters' => ['<NIP>1111111111</NIP>', '<NIP>11111111AB</NIP>', 'XSD-NIP-FORMAT', 'Podmiot1/DaneIdentyfikacyjne/NIP', 10, ['value' => '11111111AB', 'label' => 'NIP sprzedawcy'], []],
    'NIP with dashes' => ['<NIP>1111111111</NIP>', '<NIP>111-111-11-11</NIP>', 'XSD-NIP-FORMAT', 'Podmiot1/DaneIdentyfikacyjne/NIP', 10, [], []],
    'NIP with nine digits' => ['<NIP>1111111111</NIP>', '<NIP>111111111</NIP>', 'XSD-NIP-FORMAT', 'Podmiot1/DaneIdentyfikacyjne/NIP', 10, [], []],
    'NIP with a country prefix' => ['<NIP>2222222222</NIP>', '<NIP>PL2222222222</NIP>', 'XSD-NIP-FORMAT', 'Podmiot2/DaneIdentyfikacyjne/NIP', 21, ['value' => 'PL2222222222', 'label' => 'NIP nabywcy'], []],
    'email pattern' => [ADDRESS_END, "<AdresL2>00-000 Przykładowo</AdresL2>\n    </Adres><DaneKontaktowe><Email>abc</Email></DaneKontaktowe>\n  </Podmiot1>", 'XSD-PATTERN', 'Podmiot1/DaneKontaktowe/Email', 17, ['value' => 'abc', 'label' => 'adres e-mail'], ['Email']],
    'line number as text' => ['<NrWierszaFa>1</NrWierszaFa>', '<NrWierszaFa>a</NrWierszaFa>', 'XSD-INTEGER-FORMAT', 'Fa/FaWiersz/NrWierszaFa', 58, ['value' => 'a'], ['NrWierszaFa']],
    'line number with a fraction' => ['<NrWierszaFa>1</NrWierszaFa>', '<NrWierszaFa>1.5</NrWierszaFa>', 'XSD-INTEGER-FORMAT', 'Fa/FaWiersz/NrWierszaFa', 58, [], ['NrWierszaFa']],
    'flag as text' => ['<P_16>2</P_16>', '<P_16>tak</P_16>', 'XSD-INTEGER-FORMAT', 'Fa/Adnotacje/P_16', 41, [], ['P_16']],
    'line number zero' => ['<NrWierszaFa>1</NrWierszaFa>', '<NrWierszaFa>0</NrWierszaFa>', 'XSD-MIN-VALUE', 'Fa/FaWiersz/NrWierszaFa', 58, ['value' => '0', 'limit' => '0'], ['NrWierszaFa', 'exclusive']],
    'percentage above 100' => ['<P_12>23</P_12>', '<P_12_XII>101</P_12_XII>', 'XSD-MAX-VALUE', 'Fa/FaWiersz/P_12_XII', 64, ['value' => '101', 'limit' => '100'], ['P_12_XII', 'inclusive']],
    'schema version' => ['wersjaSchemy="1-0E"', 'wersjaSchemy="1-0"', 'XSD-FIXED-VALUE', 'Naglowek/KodFormularza', 4, ['value' => '1-0', 'fixed' => '1-0E', 'attribute' => 'wersjaSchemy', 'label' => 'kod formularza'], []],
    'missing attribute' => [' kodSystemowy="FA (3)"', '', 'XSD-ATTRIBUTE-MISSING', 'Naglowek/KodFormularza', 4, ['attribute' => 'kodSystemowy', 'label' => 'kod formularza'], ['KodFormularza']],
    'attribute not allowed' => ['<P_2>', '<P_2 typ="x">', 'XSD-ATTRIBUTE-NOT-ALLOWED', 'Fa/P_2', 35, ['attribute' => 'typ'], ['P_2']],
    'markup in a text field' => ['<P_7>Przykładowa usługa</P_7>', '<P_7>Usługa <b>A</b></P_7>', 'XSD-CHILD-NOT-ALLOWED', 'Fa/FaWiersz/P_7', 59, ['label' => 'nazwa towaru lub usługi'], ['P_7']],
    'text in a section' => ['<Adnotacje>', '<Adnotacje>tekst', 'XSD-TEXT-NOT-ALLOWED', 'Fa/Adnotacje', 40, ['label' => 'adnotacje'], ['Adnotacje']],
    'root in a foreign namespace' => ['<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">', '<Faktura xmlns="urn:x">', 'XSD-UNMAPPED', 'Faktura', 2, [], []],
]);

it('keeps the raw message of an unmapped error', function () {
    $error = classifyFirst(mutated('<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">', '<Faktura xmlns="urn:x">'));

    expect($error->params)->toBe(['detail' => "Element '{urn:x}Faktura': No matching global declaration available for the validation root."]);
});

it('maps an error it does not know to the fallback', function (int $code, string $message, ?string $field) {
    $invoice = invoiceOf(fixtureBytes('valid-minimal.xml'));
    $error = libxmlError($code, $message, 35);

    $classified = xsdClassifier()->classify($error, $invoice);

    expect($classified)->toEqual(new XsdError('XSD-UNMAPPED', $field, 35, ['detail' => $message]))
        ->and($classified->field)->toBe($field);
})->with([
    'unknown code' => [1999, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': Something new.", 'Fa/P_2'],
    'known code with a new wording' => [1824, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': Something new.", 'Fa/P_2'],
    'facet with a new wording' => [1840, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': [facet 'enumeration'] Something new.", 'Fa/P_2'],
    'structure with a new wording' => [1871, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': Something new.", 'Fa/P_2'],
    'upper bound with a new wording' => [1834, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': [facet 'maxInclusive'] The value '101' is greater than something.", 'Fa/P_2'],
    'atomic type of another kind' => [1824, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': 'x' is not a valid value of the atomic type 'xs:anyURI'.", 'Fa/P_2'],
    'local atomic type on another field' => [1824, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': 'x' is not a valid value of the local atomic type.", 'Fa/P_2'],
    'length facet without a length' => [1832, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': [facet 'maxLength'] Something new.", 'Fa/P_2'],
    'element missing from the document' => [1999, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_99': Something new.", 'P_99'],
    'schema error without an element' => [3004, 'complex type: The content model is not determinist.', null],
    'fraction digits without a value' => [1838, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': [facet 'fractionDigits'] Something allowed ('2').", 'Fa/P_2'],
    'pattern without a value' => [1839, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': [facet 'pattern'] Something new.", 'Fa/P_2'],
    'lower bound without a value' => [1835, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': [facet 'minExclusive'] Something must be greater than '0'.", 'Fa/P_2'],
    'fixed value without a value' => [1874, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': Something does not match the fixed value constraint '1'.", 'Fa/P_2'],
    'structure error on the root' => [1871, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}Faktura': This element is not expected. Expected is ( {http://crd.gov.pl/wzor/2025/06/25/13775/}Inna ).", 'Faktura'],
]);

it('reports an empty value outside a set as empty', function () {
    $error = libxmlError(1840, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_16': [facet 'enumeration'] The value '' is not an element of the set {'1', '2'}.", 41);

    expect(xsdClassifier()->classify($error, invoiceOf(fixtureBytes('valid-minimal.xml'))))->toEqual(new XsdError(
        'XSD-EMPTY',
        'Fa/Adnotacje/P_16',
        41,
        ['field' => 'P_16', 'label' => 'znacznik metody kasowej'],
        ['P_16'],
    ));
});

it('compares only the first 20 elements on one line', function () {
    $broken = str_replace('<NrWierszaFa>1</NrWierszaFa>', '', minimalRow());
    $bytes = str_replace("\n", '', str_replace(minimalRow(), str_repeat(minimalRow(), 20).$broken, fixtureBytes('valid-minimal.xml')));
    $error = classifyFirst($bytes);

    expect($error->id)->toBe('XSD-MISSING-ELEMENT')
        ->and($error->field)->toBe('NrWierszaFa');
});

it('finds the row among 20 elements on one line', function () {
    $broken = str_replace('<NrWierszaFa>1</NrWierszaFa>', '', minimalRow());
    $bytes = str_replace("\n", '', str_replace(minimalRow(), str_repeat(minimalRow(), 19).$broken, fixtureBytes('valid-minimal.xml')));

    expect(classifyFirst($bytes)->field)->toBe('Fa/FaWiersz[20]/NrWierszaFa');
});

it('accepts a numeric lower bound', function () {
    $invoice = invoiceOf(fixtureBytes('valid-minimal.xml'));
    $error = libxmlError(1833, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_8B': [facet 'minInclusive'] The value '-1' is less than the minimum value allowed ('0').", 61);

    expect(xsdClassifier()->classify($error, $invoice))->toEqual(new XsdError(
        'XSD-MIN-VALUE',
        'Fa/FaWiersz/P_8B',
        61,
        ['field' => 'P_8B', 'label' => 'ilość', 'value' => '-1', 'limit' => '0'],
        ['P_8B', 'inclusive'],
    ));
});

it('accepts a numeric upper bound', function () {
    $invoice = invoiceOf(fixtureBytes('valid-minimal.xml'));
    $error = libxmlError(1834, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_8B': [facet 'maxInclusive'] The value '1000' is greater than the maximum value allowed ('999').", 61);

    expect(xsdClassifier()->classify($error, $invoice))->toEqual(new XsdError(
        'XSD-MAX-VALUE',
        'Fa/FaWiersz/P_8B',
        61,
        ['field' => 'P_8B', 'label' => 'ilość', 'value' => '1000', 'limit' => '999'],
        ['P_8B', 'inclusive'],
    ));
});

it('maps a fixed element value', function () {
    $invoice = invoiceOf(fixtureBytes('valid-minimal.xml'));
    $error = libxmlError(1874, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}WariantFormularza': The value '2' does not match the fixed value constraint '3'.", 5);

    expect(xsdClassifier()->classify($error, $invoice))->toEqual(new XsdError(
        'XSD-FIXED-VALUE',
        'Naglowek/WariantFormularza',
        5,
        ['field' => 'WariantFormularza', 'label' => 'wariant formularza', 'value' => '2', 'fixed' => '3'],
    ));
});

it('shortens long values', function () {
    $error = classifyFirst(mutated('<P_15>123.00</P_15>', '<P_15>'.str_repeat('ą', 70).'</P_15>'));

    expect($error->params['value'])->toBe(str_repeat('ą', 59).'…');
});

it('keeps values up to 60 characters', function () {
    $error = classifyFirst(mutated('<P_15>123.00</P_15>', '<P_15>'.str_repeat('ą', 60).'</P_15>'));

    expect($error->params['value'])->toBe(str_repeat('ą', 60));
});

it('omits a long set of allowed values', function () {
    $error = classifyFirst(mutated('<KodWaluty>PLN', '<KodWaluty>pln'));

    expect($error->params)->not->toHaveKey('allowed');
});

it('lists a set of 20 allowed values', function () {
    $set = implode(', ', array_map(fn (int $number): string => "'{$number}'", range(1, 20)));
    $error = libxmlError(1840, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}P_2': [facet 'enumeration'] The value '0' is not an element of the set {{$set}}.", 35);

    $classified = xsdClassifier()->classify($error, invoiceOf(fixtureBytes('valid-minimal.xml')));

    expect($classified->params)->toHaveKey('allowed', implode(', ', range(1, 20)))
        ->and($classified->hints)->toBe(['P_2']);
});

it('narrows the field of a minified invoice by value', function () {
    $bytes = str_replace("\n", '', mutated('<NIP>2222222222</NIP>', '<NIP>PL2222222222</NIP>'));
    $error = classifyFirst($bytes);

    expect($error->id)->toBe('XSD-NIP-FORMAT')
        ->and($error->line)->toBe(1)
        ->and($error->field)->toBe('Podmiot2/DaneIdentyfikacyjne/NIP')
        ->and($error->params['label'])->toBe('NIP nabywcy');
});

it('falls back to the element name when a minified invoice is ambiguous', function () {
    $bytes = str_replace(["\n", '<NIP>1111111111</NIP>', '<NIP>2222222222</NIP>'], ['', '<NIP>PL1</NIP>', '<NIP>PL1</NIP>'], fixtureBytes('valid-minimal.xml'));
    $invoice = invoiceOf($bytes);
    $errors = schemaErrorsOf($invoice);

    expect($errors)->toHaveCount(2)
        ->and(xsdClassifier()->classify($errors[0], $invoice))->toEqual(new XsdError('XSD-NIP-FORMAT', 'NIP', 1, ['field' => 'NIP', 'label' => 'NIP', 'value' => 'PL1']))
        ->and(xsdClassifier()->classify($errors[1], $invoice)->field)->toBe('NIP');
});

it('finds the row with a missing element in a minified invoice', function () {
    $broken = str_replace('<NrWierszaFa>1</NrWierszaFa>', '', minimalRow());
    $bytes = str_replace("\n", '', str_replace(minimalRow(), minimalRow().minimalRow().$broken, fixtureBytes('valid-minimal.xml')));

    expect(classifyFirst($bytes))->toEqual(new XsdError(
        'XSD-MISSING-ELEMENT',
        'Fa/FaWiersz[3]/NrWierszaFa',
        1,
        ['field' => 'NrWierszaFa', 'label' => 'numer wiersza', 'parent' => 'wiersz faktury'],
        ['NrWierszaFa'],
    ));
});

it('keeps the element name when rows of a minified invoice cannot be told apart', function () {
    $broken = str_replace('<NrWierszaFa>1</NrWierszaFa>', '', minimalRow());
    $bytes = str_replace("\n", '', str_replace(minimalRow(), $broken.$broken, fixtureBytes('valid-minimal.xml')));

    expect(classifyFirst($bytes))->toEqual(new XsdError(
        'XSD-MISSING-ELEMENT',
        'NrWierszaFa',
        1,
        ['field' => 'NrWierszaFa', 'label' => 'numer wiersza', 'parent' => 'wiersz faktury'],
        ['NrWierszaFa'],
    ));
});

it('renders a polish explanation for the finding', function (string $search, string $replace, string $message, string $fix) {
    $error = classifyFirst(mutated($search, $replace));
    $finding = (new FindingFactory(RuleCatalog::fromDirectory(rulesPath())))->create($error->id, $error->field, $error->line, $error->params, $error->hints);

    expect($finding->message)->toBe($message)
        ->and($finding->fix)->toBe($fix);
})->with([
    'missing invoice number' => ['<P_2>FV/1/02/2026</P_2>', '', 'Brakuje wymaganego pola: numer faktury (sekcja: dane faktury). Po poprawce sprawdź plik ponownie, bo mogą pojawić się kolejne błędy w tej części pliku.', 'Wpisz numer faktury nadany w Twoim programie do fakturowania.'],
    'comma in an amount' => ['<P_15>123.00</P_15>', '<P_15>123,00</P_15>', 'Pole kwota należności ogółem ma wartość „123,00”, która nie jest poprawną liczbą.', 'Zamień przecinek na kropkę, na przykład 123.45 zamiast 123,45.'],
    'rate set' => ['<P_16>2</P_16>', '<P_16>3</P_16>', 'Pole znacznik metody kasowej nie przyjmuje wartości „3”.', 'Wpisz 1, jeśli to dotyczy faktury, albo 2, jeśli nie dotyczy.'],
    'set without a hint' => ['</FaWiersz>', '</FaWiersz><Platnosc><RachunekBankowy><NrRB>1234567890</NrRB><RachunekWlasnyBanku>5</RachunekWlasnyBanku></RachunekBankowy></Platnosc>', 'Pole RachunekWlasnyBanku nie przyjmuje wartości „5”.', 'Wpisz jedną z wartości: 1, 2, 3.'],
    'percentage above 100' => ['<P_12>23</P_12>', '<P_12_XII>101</P_12_XII>', 'Pole stawka VAT w procedurze OSS ma wartość 101, która jest za duża.', 'Wpisz wartość równą co najwyżej 100.'],
    'creation date too early' => ['2026-02-15T09:30:00Z', '2025-08-31T10:00:00Z', 'Pole data i godzina utworzenia pliku ma datę 2025-08-31T10:00:00Z, której wzór FA(3) nie przyjmuje.', 'Wpisz rzeczywistą datę i godzinę utworzenia pliku. Wzór przyjmuje daty od 2025-09-01T00:00:00Z.'],
]);

it('shortens a long value from its start', function () {
    $error = classifyFirst(mutated('<P_15>123.00</P_15>', '<P_15>Z'.str_repeat('ą', 69).'</P_15>'));

    expect($error->params['value'])->toBe('Z'.str_repeat('ą', 58).'…');
});

it('keeps the fallback for a structure error on the root element', function () {
    $error = libxmlError(1871, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}Faktura': This element is not expected. Expected is ( {http://crd.gov.pl/wzor/2025/06/25/13775/}Inna ).", 2);

    expect(xsdClassifier()->classify($error, invoiceOf(fixtureBytes('valid-minimal.xml')))->id)->toBe('XSD-UNMAPPED');
});

it('needs a local type to read a creation date error', function (string $message) {
    $error = libxmlError(1824, "Element '{http://crd.gov.pl/wzor/2025/06/25/13775/}DataWytworzeniaFa': {$message}", 6);

    expect(xsdClassifier()->classify($error, invoiceOf(fixtureBytes('valid-minimal.xml')))->id)->toBe('XSD-UNMAPPED');
})->with([
    'atomic type without a name' => ["'x' is not a valid value of the atomic type."],
    'another wording' => ["'x' is not a valid value of the union type 'TDataCzas'."],
]);

it('names the expected element with its section label', function () {
    $error = classifyFirst(mutated(
        "<NIP>1111111111</NIP>\n      <Nazwa>Przykładowy Sprzedawca Sp. z o.o.</Nazwa>",
        "<Nazwa>Przykładowy Sprzedawca Sp. z o.o.</Nazwa>\n      <NIP>1111111111</NIP>",
    ));

    expect($error->id)->toBe('XSD-ELEMENT-ORDER')
        ->and($error->field)->toBe('Podmiot1/DaneIdentyfikacyjne/Nazwa')
        ->and($error->params['label'])->toBe('nazwa sprzedawcy')
        ->and($error->params['expected'])->toBe('NIP sprzedawcy');
});

it('labels an ambiguous field of a minified invoice by its name', function () {
    $bytes = str_replace(["\n", '<NIP>1111111111</NIP>', '<NIP>2222222222</NIP>'], ['', '', ''], fixtureBytes('valid-minimal.xml'));
    $error = classifyFirst($bytes);

    expect($error->id)->toBe('XSD-MISSING-ELEMENT')
        ->and($error->field)->toBe('NIP')
        ->and($error->params['label'])->toBe('NIP');
});

it('narrows a minified invoice by a value written with spaces', function () {
    $contact = fn (string $email): string => "<DaneKontaktowe><Email>{$email}</Email></DaneKontaktowe>";
    $bytes = str_replace("\n", '', str_replace(
        ["</Adres>\n  </Podmiot1>", "</Adres>\n    <JST>"],
        ["</Adres>\n".$contact('  sprzedawca  ')."\n  </Podmiot1>", "</Adres>\n".$contact('nabywca@example.com')."\n    <JST>"],
        fixtureBytes('valid-minimal.xml'),
    ));

    $error = classifyFirst($bytes);

    expect($error->id)->toBe('XSD-PATTERN')
        ->and($error->field)->toBe('Podmiot1/DaneKontaktowe/Email');
});

it('prefers an unexpected element over a missing one in a minified invoice', function () {
    $bytes = str_replace("\n", '', mutated('<P_6>', '<P_12>23</P_12><P_6>'));
    $error = classifyFirst($bytes);

    expect($error->id)->toBe('XSD-UNEXPECTED-ELEMENT')
        ->and($error->field)->toBe('Fa/P_12');
});

it('prefers a wrong order over a missing element in a minified invoice', function () {
    $swapped = str_replace(
        "<NrWierszaFa>1</NrWierszaFa>\n      <P_7>Przykładowa usługa</P_7>",
        "<P_7>Przykładowa usługa</P_7>\n      <NrWierszaFa>1</NrWierszaFa>",
        minimalRow(),
    );
    $unnumbered = str_replace('<NrWierszaFa>1</NrWierszaFa>', '', minimalRow());
    $bytes = str_replace("\n", '', str_replace(minimalRow(), $swapped.$unnumbered, fixtureBytes('valid-minimal.xml')));
    $error = classifyFirst($bytes);

    expect($error->id)->toBe('XSD-ELEMENT-ORDER')
        ->and($error->field)->toBe('Fa/FaWiersz[1]/P_7');
});

it('prefers a repeated element over an unexpected one in a minified invoice', function () {
    $bytes = str_replace("\n", '', str_replace(
        ['<P_2>FV/1/02/2026</P_2>', '<P_12>23</P_12>'],
        ['<P_2>FV/1/02/2026</P_2><P_2>FV/2/02/2026</P_2>', '<P_12>23</P_12><P_2>FV/3/02/2026</P_2>'],
        fixtureBytes('valid-minimal.xml'),
    ));
    $error = classifyFirst($bytes);

    expect($error->id)->toBe('XSD-ELEMENT-REPEATED')
        ->and($error->field)->toBe('Fa/P_2[2]');
});

it('classifies only the first 20 elements on one line', function () {
    $repeated = str_replace('<P_8A>szt.</P_8A>', '<P_8A>szt.</P_8A><P_8A>kg</P_8A>', minimalRow());
    $bytes = str_replace("\n", '', str_replace(minimalRow(), str_repeat(minimalRow(), 20).$repeated, fixtureBytes('valid-minimal.xml')));
    $error = classifyFirst($bytes);

    expect($error->field)->toBe('P_8A')
        ->and($error->id)->not->toBe('XSD-ELEMENT-REPEATED');
});

const FA3_PREFIX = '{http://crd.gov.pl/wzor/2025/06/25/13775/}';

function classifyMessage(string $bytes, string $element, string $text, int $line): XsdError
{
    return xsdClassifier()->classify(libxmlError(1871, "Element '".FA3_PREFIX."{$element}': {$text}", $line), invoiceOf($bytes));
}

it('picks the first required child the message expects', function () {
    $bytes = mutated(
        "<KodKraju>PL</KodKraju>\n      <AdresL1>ul. Przykładowa 1</AdresL1>\n      <AdresL2>00-000 Przykładowo</AdresL2>",
        '<Inne>tekst</Inne>',
    );

    $error = classifyMessage($bytes, 'Adres', 'Missing child element(s). Expected is ( '.FA3_PREFIX.'KodKraju, '.FA3_PREFIX.'AdresL1 ).', 13);

    expect($error->field)->toBe('Podmiot1/Adres/KodKraju');
});

it('falls back to the first expected element when no child of the section fits', function () {
    $error = classifyMessage(fixtureBytes('valid-minimal.xml'), 'Adres', 'Missing child element(s). Expected is ( '.FA3_PREFIX.'Inne ).', 13);

    expect($error->id)->toBe('XSD-MISSING-ELEMENT')
        ->and($error->field)->toBe('Podmiot1/Adres/Inne');
});

it('falls back to the first expected element when the reported element comes too late', function () {
    $bytes = mutated("<KodWaluty>PLN</KodWaluty>\n    <P_1>2026-02-15</P_1>", "<P_1>2026-02-15</P_1>\n    <KodWaluty>PLN</KodWaluty>");

    $error = classifyMessage($bytes, 'KodWaluty', 'This element is not expected. Expected is ( '.FA3_PREFIX.'Inne ).', 34);

    expect($error->id)->toBe('XSD-MISSING-ELEMENT')
        ->and($error->field)->toBe('Fa/Inne');
});

it('looks for the missing element only before the reported one', function () {
    $bytes = mutated('<P_2>FV/1/02/2026</P_2>', '<P_1M>Przykładowo</P_1M>');

    $error = classifyMessage($bytes, 'P_1M', 'This element is not expected. Expected is ( '.FA3_PREFIX.'Inne, '.FA3_PREFIX.'P_2 ).', 35);

    expect($error->id)->toBe('XSD-MISSING-ELEMENT')
        ->and($error->field)->toBe('Fa/Inne');
});
