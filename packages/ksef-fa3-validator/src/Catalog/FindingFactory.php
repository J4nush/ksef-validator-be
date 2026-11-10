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
     */
    public function create(string $id, ?string $field = null, ?int $line = null, array $params = []): Finding
    {
        $entry = $this->catalog->entry($id);
        $replacements = [];

        foreach ($params as $name => $value) {
            $replacements['{'.$name.'}'] = $value;
        }

        return new Finding(
            id: $entry->id,
            severity: $entry->severity,
            field: $field,
            line: $line,
            message: strtr($entry->message, $replacements),
            fix: strtr($entry->fix, $replacements),
            docsUrl: self::DOCS_PATH.$entry->slug,
        );
    }

    public function skipped(string $id): SkippedCheck
    {
        return new SkippedCheck($id, $this->catalog->entry($id)->message);
    }
}
