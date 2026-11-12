<?php

declare(strict_types=1);

namespace KsefFa3\Report;

final readonly class Finding
{
    /**
     * @param  array<string, string>  $params
     */
    public function __construct(
        public string $id,
        public Severity $severity,
        public Layer $stage,
        public string $title,
        public ?string $field,
        public ?int $line,
        public string $message,
        public string $fix,
        public string $docsUrl,
        public array $params,
    ) {}
}
