<?php

declare(strict_types=1);

namespace KsefFa3\Catalog;

use Symfony\Component\Yaml\Yaml;
use UnexpectedValueException;

final readonly class FieldLabels
{
    /**
     * @param  array<string, string>  $labels
     */
    private function __construct(private array $labels) {}

    public static function fromFile(string $file): self
    {
        $data = Yaml::parseFile($file);

        if (! is_array($data) || $data === [] || array_is_list($data)) {
            throw new UnexpectedValueException("Field labels in {$file} are not a mapping.");
        }

        $labels = [];

        foreach ($data as $field => $label) {
            if (! is_string($label) || $label === '') {
                throw new UnexpectedValueException("Field labels in {$file} need a non empty label for {$field}.");
            }

            $labels[(string) $field] = $label;
        }

        return new self($labels);
    }

    public function for(string $field): string
    {
        $steps = explode('/', (string) preg_replace('/\[\d+]/', '', $field));

        while ($steps !== []) {
            $label = $this->labels[implode('/', $steps)] ?? null;

            if ($label !== null) {
                return $label;
            }

            array_shift($steps);
        }

        return (string) preg_replace('/\[\d+]$/', '', basename($field));
    }
}
