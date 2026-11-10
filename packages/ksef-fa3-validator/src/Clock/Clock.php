<?php

declare(strict_types=1);

namespace KsefFa3\Clock;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
