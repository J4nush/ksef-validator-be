<?php

declare(strict_types=1);

namespace KsefFa3\Catalog;

use KsefFa3\Report\Layer;
use KsefFa3\Report\Severity;
use LogicException;
use Symfony\Component\Yaml\Yaml;
use UnexpectedValueException;

final readonly class RuleCatalog
{
    /**
     * @param  array<string, CatalogEntry>  $entries
     */
    private function __construct(private array $entries) {}

    public static function fromDirectory(string $directory): self
    {
        $files = glob($directory.'/*.yaml') ?: [];

        if ($files === []) {
            throw new UnexpectedValueException("No rule files found in {$directory}.");
        }

        $entries = [];

        foreach ($files as $file) {
            $entry = self::load($file);
            $entries[$entry->id] = $entry;
        }

        return new self($entries);
    }

    public function entry(string $id): CatalogEntry
    {
        return $this->entries[$id] ?? throw new LogicException("Rule {$id} is not in the catalog.");
    }

    /**
     * @return list<CatalogEntry>
     */
    public function entries(): array
    {
        $entries = $this->entries;
        ksort($entries, SORT_STRING);

        return array_values($entries);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->entries);
    }

    private static function load(string $file): CatalogEntry
    {
        $name = basename($file);
        $data = Yaml::parseFile($file);

        if (! is_array($data)) {
            throw new UnexpectedValueException("Rule file {$name} is not a mapping.");
        }

        $values = [];

        foreach (['id', 'severity', 'title', 'message', 'fix', 'slug'] as $key) {
            $value = $data[$key] ?? null;

            if (! is_string($value) || $value === '') {
                throw new UnexpectedValueException("Rule file {$name} needs a non empty {$key}.");
            }

            $values[$key] = $value;
        }

        if ($name !== $values['id'].'.yaml') {
            throw new UnexpectedValueException("Rule file {$name} declares id {$values['id']}.");
        }

        $severity = Severity::tryFrom($values['severity'])
            ?? throw new UnexpectedValueException("Rule file {$name} has an unknown severity.");

        return new CatalogEntry(
            id: $values['id'],
            severity: $severity,
            stage: self::stage($data, $name),
            title: $values['title'],
            message: $values['message'],
            fix: $values['fix'],
            slug: $values['slug'],
            hints: self::hints($data['hints'] ?? [], $name),
        );
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function stage(array $data, string $name): ?Layer
    {
        if (array_key_exists('stage', $data) && $data['stage'] === null) {
            return null;
        }

        $stage = $data['stage'] ?? null;
        $layer = is_string($stage) ? Layer::tryFrom($stage) : null;

        return $layer ?? throw new UnexpectedValueException("Rule file {$name} needs a stage that names a layer or null.");
    }

    /**
     * @return array<string, string>
     */
    private static function hints(mixed $data, string $name): array
    {
        if (! is_array($data) || array_is_list($data) && $data !== []) {
            throw new UnexpectedValueException("Rule file {$name} has invalid hints.");
        }

        $hints = [];

        foreach ($data as $key => $hint) {
            if (! is_string($hint) || $hint === '') {
                throw new UnexpectedValueException("Rule file {$name} has invalid hints.");
            }

            $hints[(string) $key] = $hint;
        }

        return $hints;
    }
}
