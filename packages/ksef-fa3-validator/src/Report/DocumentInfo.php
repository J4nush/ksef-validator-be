<?php

declare(strict_types=1);

namespace KsefFa3\Report;

final readonly class DocumentInfo
{
    public function __construct(
        public ?string $type,
        public ?string $variant,
        public ?string $schema,
        public int $sizeBytes,
        public string $sha256Base64Url,
    ) {}
}
