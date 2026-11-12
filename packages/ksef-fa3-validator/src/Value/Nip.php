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
        return preg_match('/^\d{10}$/D', $value) === 1 && self::checkDigit($value) === (int) $value[9];
    }

    public static function checkDigit(string $digits): ?int
    {
        $values = array_map(intval(...), str_split(substr($digits, 0, 9)));
        $sum = 0;

        foreach ([6, 5, 7, 2, 3, 4, 5, 6, 7] as $position => $weight) {
            $sum += $values[$position] * $weight;
        }

        $checkDigit = $sum % 11;

        return $checkDigit === 10 ? null : $checkDigit;
    }

    public function value(): string
    {
        return $this->value;
    }
}
