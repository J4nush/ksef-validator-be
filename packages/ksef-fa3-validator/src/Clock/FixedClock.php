<?php

declare(strict_types=1);

namespace KsefFa3\Clock;

use DateTimeImmutable;

final readonly class FixedClock implements Clock
{
    private function __construct(private DateTimeImmutable $moment) {}

    public static function at(string $iso8601): self
    {
        return new self(new DateTimeImmutable($iso8601));
    }

    public function now(): DateTimeImmutable
    {
        return $this->moment;
    }
}
