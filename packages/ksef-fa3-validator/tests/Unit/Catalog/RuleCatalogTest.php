<?php

declare(strict_types=1);

use KsefFa3\Catalog\CatalogEntry;
use KsefFa3\Catalog\RuleCatalog;
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
    'RULE-TRUNCATED',
    'TECH-PROCESSING-INSTRUCTION',
    'TECH-SIZE-WITHOUT-ATTACHMENT',
    'TECH-ATTACHMENT-BATCH-ONLY',
    'TECH-NIP-CHECKSUM',
    'TECH-INTERNAL-ID-CHECKSUM',
    'TECH-P1-FUTURE',
    'TECH-LINE-NUMBER-DUPLICATE',
    'KSEF-DUPLICATE',
];

const CATALOG_KEYS = ['id', 'severity', 'title', 'message', 'fix', 'slug'];

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
    return "id: {$id}\nseverity: {$severity}\ntitle: Tytuł\nmessage: Komunikat {x}.\nfix: Popraw {x}.\nslug: test-regula\n";
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

    expect(array_keys($data))->toEqualCanonicalizing(CATALOG_KEYS)
        ->and($data['id'])->toBe(basename($file, '.yaml'))
        ->and(Severity::tryFrom(is_string($data['severity']) ? $data['severity'] : ''))->not->toBeNull()
        ->and($data['slug'])->toBeString()->toMatch('/^[a-z0-9-]+$/D');

    foreach (CATALOG_KEYS as $key) {
        expect($data[$key])->toBeString()->toMatch('/^[^\x{2013}\x{2014}]+$/u');
    }
})->with('shipped rule files');

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
    ['TECH-DISCOURAGED-CHAR', ['{count}']],
    ['TECH-PROCESSING-INSTRUCTION', ['{count}']],
    ['RULE-TRUNCATED', ['{id}', '{shown}', '{total}']],
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
            title: 'Tytuł',
            message: 'Komunikat {x}.',
            fix: 'Popraw {x}.',
            slug: 'test-regula',
        ));
});

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
