<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

use BcMath\Number;
use DOMElement;

final readonly class AmountField
{
    public function __construct(
        public DOMElement $element,
        public string $text,
        public Number $value,
    ) {}

    public static function of(DOMElement $element): ?self
    {
        $text = trim($element->textContent);
        $value = Decimal::parse($text);

        return $value === null ? null : new self($element, $text, $value);
    }
}
