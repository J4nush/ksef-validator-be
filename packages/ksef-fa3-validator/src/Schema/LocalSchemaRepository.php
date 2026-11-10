<?php

declare(strict_types=1);

namespace KsefFa3\Schema;

use KsefFa3\Xml\FileUri;
use UnexpectedValueException;

final readonly class LocalSchemaRepository implements SchemaRepository
{
    private const string FA3_SCHEMA = 'schemat_FA(3)_v1-0E.xsd';

    private const string STRUCTURES_SCHEMA = 'bazowe/StrukturyDanych_v10-0E.xsd';

    private const string REMOTE_STRUCTURES_LOCATION = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2022/01/05/eD/DefinicjeTypy/StrukturyDanych_v10-0E.xsd';

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $directory ??= dirname(__DIR__, 2).'/resources/schemas';
        $realDirectory = realpath($directory);

        if ($realDirectory === false || ! is_dir($realDirectory)) {
            throw new UnexpectedValueException("Schema directory {$directory} does not exist.");
        }

        $this->directory = $realDirectory;
    }

    public function fa3SchemaSource(): string
    {
        $path = $this->directory.'/'.self::FA3_SCHEMA;
        $schema = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($schema === false) {
            throw new UnexpectedValueException("Cannot read schema {$path}.");
        }

        $replacements = substr_count($schema, self::remoteImport());

        // A different count means MF changed the schema and the offline mapping must be reviewed.
        if ($replacements !== 1) {
            throw new UnexpectedValueException("Expected exactly one remote import in {$path}, found {$replacements}.");
        }

        return str_replace(
            self::remoteImport(),
            'schemaLocation="'.FileUri::fromPath($this->directory.'/'.self::STRUCTURES_SCHEMA).'"',
            $schema,
        );
    }

    public function schemaDirectory(): string
    {
        return $this->directory;
    }

    public function source(): SchemaSource
    {
        return SchemaSource::fromJsonFile($this->directory.'/source.json');
    }

    private static function remoteImport(): string
    {
        return 'schemaLocation="'.self::REMOTE_STRUCTURES_LOCATION.'"';
    }
}
