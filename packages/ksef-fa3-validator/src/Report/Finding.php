<?php

declare(strict_types=1);

namespace KsefFa3\Report;

final readonly class Finding
{
    public function __construct(
        public string $id,
        public Severity $severity,
        public ?string $field,
        public ?int $line,
        public string $message,
        public string $fix,
        public string $docsUrl,
    ) {}
}
