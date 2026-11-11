<?php

declare(strict_types=1);

namespace KsefFa3\Catalog;

use KsefFa3\Report\Finding;
use KsefFa3\Report\SkippedCheck;

final readonly class FindingFactory
{
    public const string DOCS_PATH = '/ksef/bledy/';

    public function __construct(private RuleCatalog $catalog) {}

    /**
     * @param  array<string, string|int>  $params
     * @param  list<string>  $hints
     */
    public function create(string $id, ?string $field = null, ?int $line = null, array $params = [], array $hints = []): Finding
    {
        $entry = $this->catalog->entry($id);
        $fix = $entry->fix;

        foreach ($hints as $hint) {
            if (isset($entry->hints[$hint])) {
                $fix = $entry->hints[$hint];
                break;
            }
        }

        $replacements = self::replacements($params);

        return new Finding(
            id: $entry->id,
            severity: $entry->severity,
            field: $field,
            line: $line,
            message: strtr($entry->message, $replacements),
            fix: strtr($fix, $replacements),
            docsUrl: self::DOCS_PATH.$entry->slug,
        );
    }

    /**
     * @param  array<string, string|int>  $params
     */
    public function skipped(string $id, array $params = []): SkippedCheck
    {
        return new SkippedCheck($id, strtr($this->catalog->entry($id)->message, self::replacements($params)), $params);
    }

    /**
     * @param  array<string, string|int>  $params
     * @return array<string, string|int>
     */
    private static function replacements(array $params): array
    {
        $replacements = [];

        foreach ($params as $name => $value) {
            $replacements['{'.$name.'}'] = $value;
        }

        return $replacements;
    }
}
