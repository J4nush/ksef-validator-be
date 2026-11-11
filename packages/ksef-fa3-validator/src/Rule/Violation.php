<?php

declare(strict_types=1);

namespace KsefFa3\Rule;

final readonly class Violation
{
    /**
     * @param  array<string, string|int>  $params
     * @param  list<string>  $hints
     */
    public function __construct(
        public string $id,
        public ?string $field = null,
        public ?int $line = null,
        public array $params = [],
        public array $hints = [],
    ) {}
}
