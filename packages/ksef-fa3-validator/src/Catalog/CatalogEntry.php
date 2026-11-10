<?php

declare(strict_types=1);

namespace KsefFa3\Catalog;

use KsefFa3\Report\Severity;

final readonly class CatalogEntry
{
    public function __construct(
        public string $id,
        public Severity $severity,
        public string $title,
        public string $message,
        public string $fix,
        public string $slug,
    ) {}
}
