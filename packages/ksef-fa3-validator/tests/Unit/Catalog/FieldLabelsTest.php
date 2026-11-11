<?php

declare(strict_types=1);

use KsefFa3\Catalog\FieldLabels;
use Symfony\Component\Yaml\Yaml;

covers(FieldLabels::class);

function labelsFile(string $content): string
{
    $file = sys_get_temp_dir().'/field-labels-'.bin2hex(random_bytes(6)).'.yaml';
    file_put_contents($file, $content);

    return $file;
}

function shippedLabels(): FieldLabels
{
    return FieldLabels::fromFile(dirname(__DIR__, 3).'/resources/fields/labels.yaml');
}

it('labels a field by its name, the longest known path suffix winning', function (string $field, string $label) {
    $labels = FieldLabels::fromFile(labelsFile("NIP: NIP\nPodmiot2/DaneIdentyfikacyjne/NIP: NIP nabywcy\nDaneIdentyfikacyjne/NIP: NIP z danych\nP_2: numer faktury\n"));

    expect($labels->for($field))->toBe($label);
})->with([
    'name' => ['P_2', 'numer faktury'],
    'path ending with a name' => ['Fa/P_2', 'numer faktury'],
    'full path key' => ['Podmiot2/DaneIdentyfikacyjne/NIP', 'NIP nabywcy'],
    'shorter path key' => ['Podmiot1/DaneIdentyfikacyjne/NIP', 'NIP z danych'],
    'indexed path' => ['Podmiot2[2]/DaneIdentyfikacyjne[1]/NIP', 'NIP nabywcy'],
    'bare name of an ambiguous field' => ['NIP', 'NIP'],
    'unknown field falls back to the name' => ['Fa/Uwagi', 'Uwagi'],
    'unknown indexed field' => ['Fa/FaWiersz[3]', 'FaWiersz'],
]);

it('rejects a broken labels file', function (string $content) {
    expect(fn () => FieldLabels::fromFile(labelsFile($content)))->toThrow(UnexpectedValueException::class, 'labels');
})->with([
    'not a mapping' => "- a\n",
    'empty label' => "P_2: ''\n",
    'non string label' => "P_2: [a]\n",
    'empty file' => '',
]);

it('ships short labels without dashes for the common fields', function () {
    $data = Yaml::parseFile(dirname(__DIR__, 3).'/resources/fields/labels.yaml');
    assert(is_array($data));

    expect(count($data))->toBeGreaterThanOrEqual(40);

    foreach ($data as $label) {
        expect($label)->toBeString()->toMatch('/^[^\x{2013}\x{2014}]{1,60}$/u');
    }

    expect(shippedLabels()->for('Fa/P_1'))->toBe('data wystawienia')
        ->and(shippedLabels()->for('Fa/P_2'))->toBe('numer faktury')
        ->and(shippedLabels()->for('Fa/P_15'))->toBe('kwota należności ogółem')
        ->and(shippedLabels()->for('Fa/FaWiersz[2]/P_12'))->toBe('stawka VAT')
        ->and(shippedLabels()->for('Podmiot1/DaneIdentyfikacyjne/NIP'))->toBe('NIP sprzedawcy')
        ->and(shippedLabels()->for('Podmiot2/DaneIdentyfikacyjne/NIP'))->toBe('NIP nabywcy');
});
