<?php

declare(strict_types=1);

namespace KsefFa3\Value;

final readonly class Nip
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if (! self::isValid($value)) {
            throw InvalidNip::create();
        }

        return new self($value);
    }

    public static function isValid(string $value): bool
    {
        if (preg_match('/^\d{10}$/D', $value) !== 1) {
            return false;
        }

        $digits = array_map(intval(...), str_split($value));
        $sum = 0;

        foreach ([6, 5, 7, 2, 3, 4, 5, 6, 7] as $position => $weight) {
            $sum += $digits[$position] * $weight;
        }

        return $sum % 11 === $digits[9];
    }

    public function value(): string
    {
        return $this->value;
    }
}
