<?php

declare(strict_types=1);

use KsefFa3\Catalog\CatalogEntry;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Report\Layer;
use KsefFa3\Report\Severity;
use Symfony\Component\Yaml\Yaml;

covers(RuleCatalog::class);

const CATALOG_IDS = [
    'XML-EMPTY',
    'SEC-SIZE-LIMIT',
    'TECH-ENCODING-UTF8',
    'TECH-UTF8-BOM',
    'TECH-PROLOG-ENCODING',
    'SEC-DOCTYPE',
    'TECH-DISCOURAGED-CHAR',
    'XML-MALFORMED',
    'DOC-FA2-UNSUPPORTED',
    'DOC-UNSUPPORTED',
    'XSD-UNMAPPED',
    'XSD-TRUNCATED',
    'XSD-MISSING-ELEMENT',
    'XSD-ELEMENT-ORDER',
    'XSD-ELEMENT-REPEATED',
    'XSD-UNEXPECTED-ELEMENT',
    'XSD-WRONG-NAMESPACE',
    'XSD-DECIMAL-FORMAT',
    'XSD-DECIMAL-PRECISION',
    'XSD-DATE-FORMAT',
    'XSD-DATE-RANGE',
    'XSD-MAX-LENGTH',
    'XSD-EMPTY',
    'XSD-MIN-LENGTH',
    'XSD-ENUM',
    'XSD-NIP-FORMAT',
    'XSD-PATTERN',
    'XSD-INTEGER-FORMAT',
    'XSD-MIN-VALUE',
    'XSD-MAX-VALUE',
    'XSD-ATTRIBUTE-MISSING',
    'XSD-ATTRIBUTE-NOT-ALLOWED',
    'XSD-CHILD-NOT-ALLOWED',
    'XSD-TEXT-NOT-ALLOWED',
    'XSD-FIXED-VALUE',
    'RULE-TRUNCATED',
    'TECH-PROCESSING-INSTRUCTION',
    'TECH-SIZE-WITHOUT-ATTACHMENT',
    'TECH-ATTACHMENT-BATCH-ONLY',
    'TECH-NIP-CHECKSUM',
    'TECH-INTERNAL-ID-CHECKSUM',
    'TECH-P1-FUTURE',
    'TECH-LINE-NUMBER-DUPLICATE',
    'KSEF-DUPLICATE',
    'AMT-NOT-CHECKED',
    'AMT-LINE-NET',
    'AMT-LINE-GROSS',
    'AMT-RATE-NET',
    'AMT-RATE-VAT',
    'AMT-RATE-MISSING',
    'AMT-TOTAL',
    'AMT-CURRENCY-VAT',
    'AMT-PLN-CURRENCY-FIELDS',
];

const CATALOG_KEYS = ['id', 'severity', 'title', 'message', 'fix', 'slug'];

const CATALOG_STAGES = [
    'XML-EMPTY' => 'security',
    'SEC-SIZE-LIMIT' => 'security',
    'TECH-ENCODING-UTF8' => 'security',
    'TECH-UTF8-BOM' => 'security',
    'TECH-PROLOG-ENCODING' => 'security',
    'SEC-DOCTYPE' => 'security',
    'TECH-DISCOURAGED-CHAR' => 'security',
    'XML-MALFORMED' => 'security',
    'DOC-FA2-UNSUPPORTED' => 'recognition',
    'DOC-UNSUPPORTED' => 'recognition',
    'XSD-UNMAPPED' => 'schema',
    'XSD-TRUNCATED' => 'schema',
    'XSD-MISSING-ELEMENT' => 'schema',
    'XSD-ELEMENT-ORDER' => 'schema',
    'XSD-ELEMENT-REPEATED' => 'schema',
    'XSD-UNEXPECTED-ELEMENT' => 'schema',
    'XSD-WRONG-NAMESPACE' => 'schema',
    'XSD-DECIMAL-FORMAT' => 'schema',
    'XSD-DECIMAL-PRECISION' => 'schema',
    'XSD-DATE-FORMAT' => 'schema',
    'XSD-DATE-RANGE' => 'schema',
    'XSD-MAX-LENGTH' => 'schema',
    'XSD-EMPTY' => 'schema',
    'XSD-MIN-LENGTH' => 'schema',
    'XSD-ENUM' => 'schema',
    'XSD-NIP-FORMAT' => 'schema',
    'XSD-PATTERN' => 'schema',
    'XSD-INTEGER-FORMAT' => 'schema',
    'XSD-MIN-VALUE' => 'schema',
    'XSD-MAX-VALUE' => 'schema',
    'XSD-ATTRIBUTE-MISSING' => 'schema',
    'XSD-ATTRIBUTE-NOT-ALLOWED' => 'schema',
    'XSD-CHILD-NOT-ALLOWED' => 'schema',
    'XSD-TEXT-NOT-ALLOWED' => 'schema',
    'XSD-FIXED-VALUE' => 'schema',
    'RULE-TRUNCATED' => null,
    'TECH-PROCESSING-INSTRUCTION' => 'rules',
    'TECH-SIZE-WITHOUT-ATTACHMENT' => 'rules',
    'TECH-ATTACHMENT-BATCH-ONLY' => 'rules',
    'TECH-NIP-CHECKSUM' => 'rules',
    'TECH-INTERNAL-ID-CHECKSUM' => 'rules',
    'TECH-P1-FUTURE' => 'rules',
    'TECH-LINE-NUMBER-DUPLICATE' => 'rules',
    'KSEF-DUPLICATE' => null,
    'AMT-NOT-CHECKED' => 'amounts',
    'AMT-LINE-NET' => 'amounts',
    'AMT-LINE-GROSS' => 'amounts',
    'AMT-RATE-NET' => 'amounts',
    'AMT-RATE-VAT' => 'amounts',
    'AMT-RATE-MISSING' => 'amounts',
    'AMT-TOTAL' => 'amounts',
    'AMT-CURRENCY-VAT' => 'amounts',
    'AMT-PLN-CURRENCY-FIELDS' => 'amounts',
];

const NO_DASHES = '/^[^\x{2013}\x{2014}]+$/u';

/**
 * @return array<string, mixed>
 */
function catalogYaml(string $file): array
{
    $data = Yaml::parseFile($file);
    assert(is_array($data));

    /** @var array<string, mixed> $data */
    return $data;
}

/**
 * @param  array<string, string>  $files
 */
function catalogDirectory(array $files): string
{
    $directory = sys_get_temp_dir().'/rule-catalog-'.bin2hex(random_bytes(6));
    mkdir($directory);

    foreach ($files as $name => $content) {
        file_put_contents($directory.'/'.$name, $content);
    }

    return $directory;
}

function validEntryYaml(string $id = 'TEST-RULE', string $severity = 'ERROR'): string
{
    return "id: {$id}\nseverity: {$severity}\nstage: rules\ntitle: Tytuł\nmessage: Komunikat {x}.\nfix: Popraw {x}.\nslug: test-regula\n";
}

dataset('shipped rule files', function () {
    $files = glob(rulesPath().'/*.yaml') ?: [];

    return array_combine(array_map(basename(...), $files), $files);
});

it('ships exactly the stable rule ids', function () {
    expect(RuleCatalog::fromDirectory(rulesPath())->ids())->toEqualCanonicalizing(CATALOG_IDS)
        ->and(glob(rulesPath().'/*.yaml'))->toHaveCount(count(CATALOG_IDS));
});

it('ships complete rule files', function (string $file) {
    $data = catalogYaml($file);

    expect(array_diff(array_keys($data), ['hints']))->toEqualCanonicalizing([...CATALOG_KEYS, 'stage'])
        ->and($data['id'])->toBe(basename($file, '.yaml'))
        ->and(Severity::tryFrom(is_string($data['severity']) ? $data['severity'] : ''))->not->toBeNull()
        ->and($data['slug'])->toBeString()->toMatch('/^[a-z0-9-]+$/D');

    foreach (CATALOG_KEYS as $key) {
        expect($data[$key])->toBeString()->toMatch(NO_DASHES);
    }

    $hints = $data['hints'] ?? [];
    assert(is_array($hints));

    foreach ($hints as $key => $hint) {
        expect((string) $key)->not->toBe('');
        expect($hint)->toBeString()->toMatch(NO_DASHES);
    }
})->with('shipped rule files');

it('assigns every rule to the layer that reports it', function (string $id, ?string $stage) {
    expect(RuleCatalog::fromDirectory(rulesPath())->entry($id)->stage?->value)->toBe($stage);
})->with(array_map(fn (string $id): array => [$id, CATALOG_STAGES[$id]], array_keys(CATALOG_STAGES)));

it('maps every shipped rule to a layer', function () {
    expect(array_keys(CATALOG_STAGES))->toEqualCanonicalizing(CATALOG_IDS)
        ->and(array_map(fn (Layer $layer): string => $layer->value, Layer::cases()))->toBe(['security', 'recognition', 'schema', 'rules', 'amounts']);
});

it('lists entries sorted by id', function () {
    $catalog = RuleCatalog::fromDirectory(catalogDirectory([
        'B-RULE.yaml' => validEntryYaml('B-RULE'),
        'A-RULE.yaml' => validEntryYaml('A-RULE'),
        'A-RULE-LONG.yaml' => validEntryYaml('A-RULE-LONG'),
    ]));

    expect(array_map(fn (CatalogEntry $entry): string => $entry->id, $catalog->entries()))->toBe(['A-RULE', 'A-RULE-LONG', 'B-RULE']);
});

it('lists the shipped entries in id order', function () {
    $ids = array_map(fn (CatalogEntry $entry): string => $entry->id, RuleCatalog::fromDirectory(rulesPath())->entries());
    $sorted = CATALOG_IDS;
    sort($sorted, SORT_STRING);

    expect($ids)->toBe($sorted);
});

it('loads a rule outside every layer', function () {
    $yaml = str_replace('stage: rules', 'stage: null', validEntryYaml());

    expect(RuleCatalog::fromDirectory(catalogDirectory(['TEST-RULE.yaml' => $yaml]))->entry('TEST-RULE')->stage)->toBeNull();
});

it('rejects a rule file without a valid stage', function (string $stage) {
    $yaml = str_replace("stage: rules\n", $stage, validEntryYaml());

    expect(fn () => RuleCatalog::fromDirectory(catalogDirectory(['TEST-RULE.yaml' => $yaml])))
        ->toThrow(UnexpectedValueException::class, 'TEST-RULE.yaml needs a stage');
})->with([
    'missing' => '',
    'unknown' => "stage: parse\n",
    'list' => "stage: [rules]\n",
    'upper case' => "stage: RULES\n",
]);

it('gives every schema rule a polish explanation and a fix', function (string $id) {
    $entry = RuleCatalog::fromDirectory(rulesPath())->entry($id);

    expect($entry->severity)->toBe($id === 'XSD-TRUNCATED' ? Severity::Info : Severity::Error);

    foreach ([$entry->title, $entry->message, $entry->fix, $entry->slug, ...array_values($entry->hints)] as $text) {
        expect($text)->toMatch(NO_DASHES);
        expect($text)->not->toContain('art.');
    }
})->with(array_values(array_filter(CATALOG_IDS, fn (string $id): bool => str_starts_with($id, 'XSD-'))));

it('tells the user to check the file again after a structure error', function (string $id) {
    expect(RuleCatalog::fromDirectory(rulesPath())->entry($id)->message)->toContain('sprawdź plik ponownie');
})->with(['XSD-MISSING-ELEMENT', 'XSD-ELEMENT-ORDER', 'XSD-ELEMENT-REPEATED', 'XSD-UNEXPECTED-ELEMENT', 'XSD-WRONG-NAMESPACE']);

it('ships field hints for the common schema errors', function (string $id, array $keys) {
    expect(array_keys(RuleCatalog::fromDirectory(rulesPath())->entry($id)->hints))->toContain(...$keys);
})->with([
    ['XSD-MISSING-ELEMENT', ['P_1', 'P_2', 'P_15', 'Adnotacje', 'RodzajFaktury', 'NrWierszaFa', 'P_14_1']],
    ['XSD-ENUM', ['P_12', 'KodWaluty', 'KodKraju', 'RodzajFaktury', 'P_16', 'JST', 'GV', 'P_19N', 'P_22N', 'P_PMarzyN', 'StanPrzed', 'FormaPlatnosci', 'long-list']],
    ['XSD-DATE-FORMAT', ['P_1', 'DataWytworzeniaFa', 'nonexistent']],
    ['XSD-DATE-RANGE', ['DataWytworzeniaFa', 'too-early', 'too-late']],
    ['XSD-DECIMAL-FORMAT', ['comma', 'space', 'leading-zero', 'plus', 'trailing-dot']],
    ['XSD-DECIMAL-PRECISION', ['max-2', 'max-6', 'max-8']],
    ['XSD-EMPTY', ['P_2', 'P_15']],
    ['XSD-INTEGER-FORMAT', ['NrWierszaFa']],
    ['XSD-MIN-VALUE', ['NrWierszaFa', 'inclusive', 'exclusive']],
    ['XSD-MAX-VALUE', ['inclusive']],
    ['XSD-MIN-LENGTH', ['NrRB']],
    ['XSD-PATTERN', ['Email']],
    ['XSD-ATTRIBUTE-MISSING', ['KodFormularza']],
    ['XSD-WRONG-NAMESPACE', ['no-namespace']],
]);

it('ships unique slugs', function () {
    $slugs = array_map(fn (string $id) => RuleCatalog::fromDirectory(rulesPath())->entry($id)->slug, CATALOG_IDS);

    expect(array_unique($slugs))->toHaveCount(count(CATALOG_IDS));
});

it('declares the parameters the engine fills in', function (string $id, array $placeholders) {
    $entry = RuleCatalog::fromDirectory(rulesPath())->entry($id);

    foreach ($placeholders as $placeholder) {
        expect($entry->message)->toContain($placeholder);
    }
})->with([
    ['XSD-UNMAPPED', ['{detail}']],
    ['XSD-TRUNCATED', ['{shown}', '{total}']],
    ['XSD-MISSING-ELEMENT', ['{label}', '{parent}']],
    ['XSD-ELEMENT-ORDER', ['{label}', '{expected}']],
    ['XSD-ELEMENT-REPEATED', ['{label}']],
    ['XSD-UNEXPECTED-ELEMENT', ['{field}']],
    ['XSD-WRONG-NAMESPACE', ['{field}']],
    ['XSD-DECIMAL-FORMAT', ['{label}', '{value}']],
    ['XSD-DECIMAL-PRECISION', ['{label}', '{value}', '{max}']],
    ['XSD-DATE-FORMAT', ['{label}', '{value}']],
    ['XSD-DATE-RANGE', ['{label}', '{value}']],
    ['XSD-MAX-LENGTH', ['{label}', '{length}', '{max}']],
    ['XSD-EMPTY', ['{label}']],
    ['XSD-MIN-LENGTH', ['{label}', '{length}', '{min}']],
    ['XSD-ENUM', ['{label}', '{value}']],
    ['XSD-NIP-FORMAT', ['{value}']],
    ['XSD-PATTERN', ['{label}', '{value}']],
    ['XSD-INTEGER-FORMAT', ['{label}', '{value}']],
    ['XSD-MIN-VALUE', ['{label}', '{value}']],
    ['XSD-MAX-VALUE', ['{label}', '{value}']],
    ['XSD-ATTRIBUTE-MISSING', ['{label}', '{attribute}']],
    ['XSD-ATTRIBUTE-NOT-ALLOWED', ['{label}', '{attribute}']],
    ['XSD-CHILD-NOT-ALLOWED', ['{label}']],
    ['XSD-TEXT-NOT-ALLOWED', ['{label}']],
    ['XSD-FIXED-VALUE', ['{label}', '{value}', '{fixed}']],
    ['TECH-DISCOURAGED-CHAR', ['{count}']],
    ['TECH-PROCESSING-INSTRUCTION', ['{count}']],
    ['RULE-TRUNCATED', ['{id}', '{shown}', '{total}']],
    ['AMT-NOT-CHECKED', ['{reason}']],
    ['AMT-LINE-NET', ['{label}', '{value}', '{expected}']],
    ['AMT-LINE-GROSS', ['{label}', '{value}', '{expected}']],
    ['AMT-RATE-NET', ['{label}', '{value}', '{rate}', '{expected}']],
    ['AMT-RATE-VAT', ['{label}', '{value}', '{rate}', '{net}', '{expected}']],
    ['AMT-RATE-MISSING', ['{label}', '{rate}', '{lines}', '{value}']],
    ['AMT-TOTAL', ['{label}', '{value}', '{expected}']],
    ['AMT-CURRENCY-VAT', ['{label}', '{value}', '{tax}', '{rate}', '{expected}']],
    ['AMT-PLN-CURRENCY-FIELDS', ['{label}']],
]);

it('gives every amount rule a polish warning and a fix', function (string $id) {
    $entry = RuleCatalog::fromDirectory(rulesPath())->entry($id);

    expect($entry->severity)->toBe($id === 'AMT-NOT-CHECKED' ? Severity::Info : Severity::Warning);

    foreach ([$entry->title, $entry->message, $entry->fix, ...array_values($entry->hints)] as $text) {
        expect($text)->toMatch(NO_DASHES);
        expect($text)->not->toContain('art.');
    }
})->with(array_values(array_filter(CATALOG_IDS, fn (string $id): bool => str_starts_with($id, 'AMT-'))));

it('ships the hints the amount rules ask for', function (string $id, array $keys) {
    expect(array_keys(RuleCatalog::fromDirectory(rulesPath())->entry($id)->hints))->toEqualCanonicalizing($keys);
})->with([
    ['AMT-RATE-NET', ['gross']],
    ['AMT-RATE-MISSING', ['missing-total', 'missing-rows']],
]);

it('explains where the tool differs from KSeF', function (string $id, string $phrase) {
    $entry = RuleCatalog::fromDirectory(rulesPath())->entry($id);

    expect($entry->message.' '.$entry->fix)->toContain($phrase);
})->with([
    ['TECH-P1-FUTURE', 'datą przyjęcia'],
    ['TECH-P1-FUTURE', 'dzisiejszą datą w Polsce'],
    ['TECH-LINE-NUMBER-DUPLICATE', 'KSeF tego nie sprawdza'],
    ['TECH-NIP-CHECKSUM', 'środowisku produkcyjnym'],
    ['TECH-INTERNAL-ID-CHECKSUM', 'środowisku produkcyjnym'],
]);

it('loads an entry with all fields', function () {
    $catalog = RuleCatalog::fromDirectory(catalogDirectory(['TEST-RULE.yaml' => validEntryYaml(severity: 'WARNING')]));

    expect($catalog->ids())->toBe(['TEST-RULE'])
        ->and($catalog->entry('TEST-RULE'))->toEqual(new CatalogEntry(
            id: 'TEST-RULE',
            severity: Severity::Warning,
            stage: Layer::Rules,
            title: 'Tytuł',
            message: 'Komunikat {x}.',
            fix: 'Popraw {x}.',
            slug: 'test-regula',
        ))
        ->and($catalog->entry('TEST-RULE')->hints)->toBe([]);
});

it('loads field hints', function () {
    $catalog = RuleCatalog::fromDirectory(catalogDirectory(['TEST-RULE.yaml' => validEntryYaml()."hints:\n  P_2: Wpisz numer.\n  comma: Zamień przecinek.\n"]));

    expect($catalog->entry('TEST-RULE')->hints)->toBe(['P_2' => 'Wpisz numer.', 'comma' => 'Zamień przecinek.']);
});

it('rejects broken field hints', function (string $hints) {
    expect(fn () => RuleCatalog::fromDirectory(catalogDirectory(['TEST-RULE.yaml' => validEntryYaml().$hints])))
        ->toThrow(UnexpectedValueException::class, 'TEST-RULE.yaml has invalid hints');
})->with([
    'not a mapping' => "hints: tekst\n",
    'a list' => "hints:\n  - Wpisz numer.\n",
    'empty hint' => "hints:\n  P_2: ''\n",
    'non string hint' => "hints:\n  P_2: [a]\n",
]);

it('lists ids sorted by file name', function () {
    $catalog = RuleCatalog::fromDirectory(catalogDirectory([
        'B-RULE.yaml' => validEntryYaml('B-RULE'),
        'A-RULE.yaml' => validEntryYaml('A-RULE'),
        'notes.txt' => 'ignored',
    ]));

    expect($catalog->ids())->toBe(['A-RULE', 'B-RULE']);
});

it('throws a logic exception for an unknown id', function () {
    expect(fn () => RuleCatalog::fromDirectory(rulesPath())->entry('NO-SUCH-RULE'))
        ->toThrow(LogicException::class, 'NO-SUCH-RULE');
});

it('rejects a broken rule file', function (string $file, string $content) {
    expect(fn () => RuleCatalog::fromDirectory(catalogDirectory([$file => $content])))
        ->toThrow(UnexpectedValueException::class, $file);
})->with([
    'not a mapping' => ['TEST-RULE.yaml', "- a\n- b\n"],
    'missing key' => ['TEST-RULE.yaml', "id: TEST-RULE\nseverity: ERROR\ntitle: T\nmessage: M\nfix: F\n"],
    'empty value' => ['TEST-RULE.yaml', "id: TEST-RULE\nseverity: ERROR\ntitle: ''\nmessage: M\nfix: F\nslug: s\n"],
    'non string value' => ['TEST-RULE.yaml', "id: TEST-RULE\nseverity: ERROR\ntitle: [T]\nmessage: M\nfix: F\nslug: s\n"],
    'unknown severity' => ['TEST-RULE.yaml', validEntryYaml(severity: 'FATAL')],
    'id differs from file name' => ['OTHER-RULE.yaml', validEntryYaml()],
]);

it('rejects a rule file without a required key', function (string $key) {
    $content = preg_replace('/^'.$key.': .*\n/m', '', validEntryYaml());

    expect(fn () => RuleCatalog::fromDirectory(catalogDirectory(['TEST-RULE.yaml' => (string) $content])))
        ->toThrow(UnexpectedValueException::class, "non empty {$key}");
})->with(CATALOG_KEYS);

it('rejects a directory without rule files', function () {
    expect(fn () => RuleCatalog::fromDirectory(catalogDirectory([])))
        ->toThrow(UnexpectedValueException::class);
});
