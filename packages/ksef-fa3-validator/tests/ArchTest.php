<?php

declare(strict_types=1);

arch('core is framework free')
    ->expect('KsefFa3')
    ->not->toUse(['Illuminate', 'Laravel']);

arch('strict types everywhere')
    ->expect('KsefFa3')
    ->toUseStrictTypes();

arch()->preset()->php();

test('core does not require or reference a framework', function () {
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($manifest) && is_array($manifest['require']));

    expect(array_keys($manifest['require']))
        ->each->not->toMatch('#^(laravel|illuminate)/#');

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        assert($file instanceof SplFileInfo);
        expect((string) file_get_contents($file->getPathname()))
            ->not->toMatch('/\b(Illuminate|Laravel)\\\\/');
    }
});
