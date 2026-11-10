<?php

declare(strict_types=1);

namespace KsefFa3\Value;

use InvalidArgumentException;

final class InvalidNip extends InvalidArgumentException
{
    public static function create(): self
    {
        return new self('The NIP must have 10 digits and a valid check digit.');
    }
}
