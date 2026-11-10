<?php

declare(strict_types=1);

use KsefFa3\Schema\LocalSchemaRepository;
use KsefFa3\Xml\FileUri;

covers(LocalSchemaRepository::class, FileUri::class);

const REMOTE_STRUCTURES_LOCATION = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/StrukturyDanych_v10-0E.xsd';

it('ships MF files byte for byte', function (string $file, string $sha256) {
    expect(hash_file('sha256', schemaPath($file)))->toBe($sha256);
})->with([
    ['schemat_FA(3)_v1-0E.xsd', 'b646b6b525f51adf1bb2545f111fc8ca6e7aa6dd2f98948f1667d3695c06d958'],
    ['bazowe/StrukturyDanych_v10-0E.xsd', 'cb08348374598e1e716e086c40d740390fb9e1bfa3aba1f4ec4cba0e1ef6d60f'],
    ['bazowe/ElementarneTypyDanych_v10-0E.xsd', '8daf4d3771de200b26b697294cc906a2add3de9acfbbd97f4b1bd4fc0e5ecb2f'],
    ['bazowe/KodyKrajow_v10-0E.xsd', '48be2a9f181d7ff80f185c62491ba12604c5cacbbe21af8e2aaaf2c585bbd214'],
    ['LICENSE-MF.txt', 'f813e0850875814fd117d10803221c6032ef32fe3415e9f1d37fb1a115c3c07b'],
]);

it('returns the real path of the schema directory', function () {
    expect((new LocalSchemaRepository)->schemaDirectory())->toBe(realpath(schemaPath()));
});

it('points the FA(3) import at the local base schema', function () {
    $repository = new LocalSchemaRepository;
    $source = $repository->fa3SchemaSource();

    expect($source)
        ->not->toContain('schemaLocation="'.REMOTE_STRUCTURES_LOCATION.'"')
        ->toContain('schemaLocation="file://'.$repository->schemaDirectory().'/bazowe/StrukturyDanych_v10-0E.xsd"');
});

it('changes nothing in the FA(3) schema except the import location', function () {
    $repository = new LocalSchemaRepository;
    $original = (string) file_get_contents(schemaPath('schemat_FA(3)_v1-0E.xsd'));

    expect(str_replace(
        'file://'.$repository->schemaDirectory().'/bazowe/StrukturyDanych_v10-0E.xsd',
        REMOTE_STRUCTURES_LOCATION,
        $repository->fa3SchemaSource(),
    ))->toBe($original);
});

it('encodes the local import location of a directory with reserved characters', function () {
    $directory = copySchemasTo(sys_get_temp_dir().'/ksef schemas #1 (50%)-'.bin2hex(random_bytes(4)));

    try {
        $repository = new LocalSchemaRepository($directory);
        $source = $repository->fa3SchemaSource();
    } finally {
        removeDirectory($directory);
    }

    expect($source)->toContain('schemaLocation="'.FileUri::fromPath(realpath(sys_get_temp_dir()).'/'.basename($directory)).'/bazowe/StrukturyDanych_v10-0E.xsd"')
        ->and($source)->toContain('%20schemas%20%231%20%2850%25%29-');
});

it('describes the shipped schema source', function () {
    $source = (new LocalSchemaRepository)->source();

    expect($source->ksefApiCommit)->toBe('ce3670ea042de380039de4c5849fd6455811b292')
        ->and($source->xsdSha256)->toBe(hash_file('sha256', schemaPath('schemat_FA(3)_v1-0E.xsd')))
        ->and($source->watchedPaths)->toBe([
            'faktury/schemy/FA/schemat_FA(3)_v1-0E.xsd' => '23e1ce5b7b584d86780c3145bb6c80090d81a715',
            'faktury/weryfikacja-faktury.md' => 'f1f68ba6076e349a337d0d1cffd85fe28dee09a5',
            'kody-qr.md' => 'fe49c771aa3eff2c330ef3c36cf132f3953de140',
            'limity/limity.md' => '93b843d5def041f69fe2a26d0d90a53e9fa9987a',
        ]);
});

it('refuses a schema whose import is not found exactly once', function (string $schema) {
    $directory = sys_get_temp_dir().'/ksef-fa3-schemas-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/schemat_FA(3)_v1-0E.xsd', $schema);
    copy(schemaPath('source.json'), $directory.'/source.json');

    try {
        expect(fn () => (new LocalSchemaRepository($directory))->fa3SchemaSource())
            ->toThrow(UnexpectedValueException::class);
    } finally {
        unlink($directory.'/schemat_FA(3)_v1-0E.xsd');
        unlink($directory.'/source.json');
        rmdir($directory);
    }
})->with([
    'import missing' => ['<xsd:schema/>'],
    'import twice' => [str_repeat('<xsd:import schemaLocation="'.REMOTE_STRUCTURES_LOCATION.'"/>', 2)],
]);

it('refuses a schema directory that does not exist or is a file', function (string $directory) {
    expect(fn () => new LocalSchemaRepository($directory))->toThrow(UnexpectedValueException::class);
})->with([
    'missing' => fn () => schemaPath('missing'),
    'file' => fn () => schemaPath('source.json'),
]);

it('refuses a directory without a readable FA(3) schema file', function (bool $schemaIsDirectory) {
    $directory = sys_get_temp_dir().'/ksef-fa3-empty-'.bin2hex(random_bytes(4));
    mkdir($directory);

    if ($schemaIsDirectory) {
        mkdir($directory.'/schemat_FA(3)_v1-0E.xsd');
    }

    try {
        expect(fn () => (new LocalSchemaRepository($directory))->fa3SchemaSource())
            ->toThrow(UnexpectedValueException::class, 'Cannot read schema');
    } finally {
        removeDirectory($directory);
    }
})->with([
    'missing' => false,
    'directory in its place' => true,
]);
