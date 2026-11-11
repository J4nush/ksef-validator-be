<?php

declare(strict_types=1);

use KsefFa3\Xsd\SchemaModel;

covers(SchemaModel::class);

function shippedModel(): SchemaModel
{
    return SchemaModel::fromDirectory(schemaPath());
}

/**
 * @param  list<string>  $path
 * @return array<string, bool>
 */
function childRequirements(array $path): array
{
    $children = shippedModel()->children($path);
    assert($children !== null);

    $requirements = [];

    foreach ($children as [$name, $required]) {
        $requirements[$name] ??= $required;
    }

    return $requirements;
}

/**
 * @param  array<string, string>  $files
 */
function schemaModelDirectory(array $files): string
{
    $directory = sys_get_temp_dir().'/schema-model-'.bin2hex(random_bytes(6));
    mkdir($directory.'/nested', recursive: true);
    mkdir($directory.'/a');

    foreach ($files as $name => $content) {
        file_put_contents($directory.'/'.$name, $content);
    }

    return $directory;
}

it('lists the children of the root in schema order', function () {
    $children = shippedModel()->children(['Faktura']);

    expect($children)->toBe([
        ['Naglowek', true],
        ['Podmiot1', true],
        ['Podmiot2', true],
        ['Podmiot3', false],
        ['PodmiotUpowazniony', false],
        ['Fa', true],
        ['Stopka', false],
        ['Zalacznik', false],
    ]);
});

it('treats elements in an optional group as optional', function (string $name, bool $required) {
    expect(childRequirements(['Faktura', 'Fa'])[$name])->toBe($required);
})->with([
    ['KodWaluty', true],
    ['P_1', true],
    ['P_1M', false],
    ['P_2', true],
    ['P_13_1', false],
    ['P_14_1', false],
    ['P_14_1W', false],
    ['P_15', true],
    ['Adnotacje', true],
    ['RodzajFaktury', true],
    ['FaWiersz', false],
]);

it('keeps schema order inside a section', function () {
    $position = array_flip(array_keys(childRequirements(['Faktura', 'Fa'])));

    expect($position['P_2'])->toBeLessThan($position['P_6'])
        ->and($position['P_14_1'])->toBeLessThan($position['P_15'])
        ->and($position['P_15'])->toBeLessThan($position['Adnotacje']);
});

it('follows named types and choices', function () {
    expect(array_slice(childRequirements(['Faktura', 'Podmiot2', 'DaneIdentyfikacyjne']), 0, 2, true))->toBe(['NIP' => true, 'KodUE' => true])
        ->and(childRequirements(['Faktura', 'Fa', 'Adnotacje', 'Zwolnienie']))->toBe(['P_19' => true, 'P_19A' => true, 'P_19B' => true, 'P_19C' => true, 'P_19N' => true])
        ->and(childRequirements(['Faktura', 'Podmiot1', 'Adres']))->toHaveKeys(['KodKraju', 'AdresL1', 'AdresL2']);
});

it('follows the base of an extended type', function () {
    expect(childRequirements(['Faktura', 'Podmiot1', 'AdresKoresp']))->toHaveKeys(['KodKraju', 'AdresL1']);
});

it('has no children for an unknown path or a simple element', function (array $path) {
    expect(shippedModel()->children(array_values(array_filter($path, is_string(...)))))->toBeNull();
})->with([
    'unknown root' => [['Rachunek']],
    'unknown child' => [['Faktura', 'Uwagi']],
    'empty path' => [[]],
    'simple element' => [['Faktura', 'Fa', 'P_2']],
]);

it('knows the element names declared in the schema', function () {
    expect(shippedModel()->declares('P_2'))->toBeTrue()
        ->and(shippedModel()->declares('NIP'))->toBeTrue()
        ->and(shippedModel()->declares('Uwagi'))->toBeFalse();
});

it('reads schemas from subdirectories and ignores files that are not schemas', function () {
    $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:t="urn:t" targetNamespace="urn:t">%s</xsd:schema>';
    $directory = schemaModelDirectory([
        'main.xsd' => sprintf($schema, '<xsd:element name="Root" type="t:TRoot"/>'),
        'nested/types.xsd' => sprintf($schema, '<xsd:complexType name="TRoot"><xsd:sequence><xsd:element name="A" minOccurs="0"/><xsd:element name="B"/></xsd:sequence></xsd:complexType>'),
        'broken.xsd' => 'not xml',
        'notes.txt' => '<xsd:element name="Ignored"/>',
    ]);

    try {
        $model = SchemaModel::fromDirectory($directory);

        expect($model->children(['Root']))->toBe([['A', false], ['B', true]])
            ->and($model->declares('Ignored'))->toBeFalse();
    } finally {
        removeDirectory($directory);
    }
});

it('prefers types from the top directory', function () {
    $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema">%s</xsd:schema>';
    $directory = schemaModelDirectory([
        'main.xsd' => sprintf($schema, '<xsd:element name="Root" type="T"/><xsd:complexType name="T"><xsd:sequence><xsd:element name="Top"/></xsd:sequence></xsd:complexType>'),
        'a/other.xsd' => sprintf($schema, '<xsd:complexType name="T"><xsd:sequence><xsd:element name="Nested"/></xsd:sequence></xsd:complexType>'),
    ]);

    try {
        expect(SchemaModel::fromDirectory($directory)->children(['Root']))->toBe([['Top', true]]);
    } finally {
        removeDirectory($directory);
    }
});

it('reads unordered groups and the base of an extension before its own elements', function () {
    $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema">%s</xsd:schema>';
    $directory = schemaModelDirectory([
        'main.xsd' => sprintf($schema, '<xsd:element name="Root" type="T"/>'
            .'<xsd:complexType name="Base"><xsd:annotation><xsd:documentation>Opis</xsd:documentation></xsd:annotation><xsd:all><xsd:element name="A"/><xsd:element name="B" minOccurs="0"/></xsd:all></xsd:complexType>'
            .'<xsd:complexType name="T"><xsd:complexContent><xsd:extension base="Base"><xsd:sequence><xsd:element name="C"/></xsd:sequence></xsd:extension></xsd:complexContent></xsd:complexType>'),
    ]);

    try {
        expect(SchemaModel::fromDirectory($directory)->children(['Root']))->toBe([['A', true], ['B', false], ['C', true]]);
    } finally {
        removeDirectory($directory);
    }
});

it('works without any schema', function () {
    $directory = schemaModelDirectory([]);

    try {
        $model = SchemaModel::fromDirectory($directory);

        expect($model->children(['Faktura']))->toBeNull()
            ->and($model->declares('Faktura'))->toBeFalse();
    } finally {
        removeDirectory($directory);
    }
});

it('loads the schema only when asked', function () {
    $directory = schemaModelDirectory([]);
    $model = SchemaModel::fromDirectory($directory);
    file_put_contents($directory.'/late.xsd', '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"><xsd:element name="Late"/></xsd:schema>');

    try {
        expect($model->declares('Late'))->toBeTrue();
    } finally {
        removeDirectory($directory);
    }
});

it('follows the first of two children with the same name', function () {
    $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema">%s</xsd:schema>';
    $directory = schemaModelDirectory([
        'main.xsd' => sprintf($schema, '<xsd:element name="Root"><xsd:complexType><xsd:choice>'
            .'<xsd:element name="X"><xsd:complexType><xsd:sequence><xsd:element name="First"/></xsd:sequence></xsd:complexType></xsd:element>'
            .'<xsd:element name="X"><xsd:complexType><xsd:sequence><xsd:element name="Second"/></xsd:sequence></xsd:complexType></xsd:element>'
            .'</xsd:choice></xsd:complexType></xsd:element>'),
    ]);

    try {
        expect(SchemaModel::fromDirectory($directory)->children(['Root', 'X']))->toBe([['First', true]]);
    } finally {
        removeDirectory($directory);
    }
});

it('prefers an inline type over a named one', function () {
    $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema">%s</xsd:schema>';
    $directory = schemaModelDirectory([
        'main.xsd' => sprintf($schema, '<xsd:element name="Root" type="T"><xsd:complexType><xsd:sequence><xsd:element name="Inline"/></xsd:sequence></xsd:complexType></xsd:element>'
            .'<xsd:complexType name="T"><xsd:sequence><xsd:element name="Named"/></xsd:sequence></xsd:complexType>'),
    ]);

    try {
        expect(SchemaModel::fromDirectory($directory)->children(['Root']))->toBe([['Inline', true]]);
    } finally {
        removeDirectory($directory);
    }
});

it('reads a schema with any namespace prefix', function () {
    $directory = schemaModelDirectory([
        'main.xsd' => '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="Root"><xs:complexType><xs:sequence><xs:element name="A"/></xs:sequence></xs:complexType></xs:element></xs:schema>',
    ]);

    try {
        $model = SchemaModel::fromDirectory($directory);

        expect($model->children(['Root']))->toBe([['A', true]])
            ->and($model->declares('A'))->toBeTrue();
    } finally {
        removeDirectory($directory);
    }
});

it('prefers global elements from the top directory', function () {
    $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema">%s</xsd:schema>';
    $root = '<xsd:element name="Root"><xsd:complexType><xsd:sequence><xsd:element name="%s"/></xsd:sequence></xsd:complexType></xsd:element>';
    $directory = schemaModelDirectory([
        'main.xsd' => sprintf($schema, sprintf($root, 'Top')),
        'a/other.xsd' => sprintf($schema, sprintf($root, 'Nested')),
        'nested/other.xsd' => sprintf($schema, sprintf($root, 'Deeper')),
    ]);

    try {
        expect(SchemaModel::fromDirectory($directory)->children(['Root']))->toBe([['Top', true]]);
    } finally {
        removeDirectory($directory);
    }
});

it('orders schemas of one directory by name', function () {
    $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema">%s</xsd:schema>';
    $root = '<xsd:element name="Root"><xsd:complexType><xsd:sequence><xsd:element name="%s"/></xsd:sequence></xsd:complexType></xsd:element>';
    $directory = schemaModelDirectory([
        'b.xsd' => sprintf($schema, sprintf($root, 'B')),
        'a.xsd' => sprintf($schema, sprintf($root, 'A')),
        'c.xsd' => sprintf($schema, sprintf($root, 'C')),
        'd.xsd' => sprintf($schema, sprintf($root, 'D')),
    ]);

    try {
        expect(SchemaModel::fromDirectory($directory)->children(['Root']))->toBe([['A', true]]);
    } finally {
        removeDirectory($directory);
    }
});

it('ignores schema documents in files of another type', function () {
    $directory = schemaModelDirectory([
        'notes.txt' => '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"><xsd:element name="Ignored"/></xsd:schema>',
    ]);

    try {
        expect(SchemaModel::fromDirectory($directory)->declares('Ignored'))->toBeFalse();
    } finally {
        removeDirectory($directory);
    }
});

it('reads the schema directory only once', function () {
    $schema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"><xsd:element name="%s"/></xsd:schema>';
    $directory = schemaModelDirectory(['main.xsd' => sprintf($schema, 'Early')]);
    $model = SchemaModel::fromDirectory($directory);

    try {
        expect($model->declares('Early'))->toBeTrue();

        file_put_contents($directory.'/late.xsd', sprintf($schema, 'Late'));

        expect($model->declares('Late'))->toBeFalse();
    } finally {
        removeDirectory($directory);
    }
});

it('works with a missing schema directory', function () {
    $model = SchemaModel::fromDirectory(sys_get_temp_dir().'/schema-model-missing-'.bin2hex(random_bytes(6)));

    expect($model->declares('Faktura'))->toBeFalse()
        ->and($model->children(['Faktura']))->toBeNull();
});
