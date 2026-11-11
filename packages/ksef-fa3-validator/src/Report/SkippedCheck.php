<?php

declare(strict_types=1);

namespace KsefFa3\Report;

final readonly class SkippedCheck
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(
        public string $id,
        public string $message,
        public array $params = [],
    ) {}
}
