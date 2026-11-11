<?php

declare(strict_types=1);

namespace KsefFa3\Xsd;

final readonly class XsdError
{
    /**
     * @param  array<string, string>  $params
     * @param  list<string>  $hints
     */
    public function __construct(
        public string $id,
        public ?string $field,
        public int $line,
        public array $params,
        public array $hints = [],
    ) {}
}
