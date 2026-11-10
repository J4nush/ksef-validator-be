<?php

declare(strict_types=1);

namespace KsefFa3\Report;

final readonly class SkippedCheck
{
    public function __construct(
        public string $id,
        public string $message,
    ) {}
}
