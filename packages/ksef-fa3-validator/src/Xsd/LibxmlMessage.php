<?php

declare(strict_types=1);

namespace KsefFa3\Xsd;

use LibXMLError;

final readonly class LibxmlMessage
{
    private const string PREFIX = "/^Element '(?:\{(?<namespace>[^}]*)\})?(?<element>[^']+)'(?:, attribute '(?<attribute>[^']+)')?: (?<text>.*)$/sD";

    private const string VALUE = "/^(?:\[facet '\w+'\] )?(?:The value )?'(?<value>.*?)' (?:has more|is not|is less|is greater|must be|does not)/s";

    private const string EMPTY_LENGTH = "The value has a length of '0';";

    public function __construct(
        public int $code,
        public int $line,
        public bool $hasElement,
        public ?string $namespace,
        public string $element,
        public ?string $attribute,
        public string $text,
        public ?string $value,
        public string $raw,
    ) {}

    public static function parse(LibXMLError $error): self
    {
        $raw = trim($error->message);

        if (preg_match(self::PREFIX, $raw, $match) !== 1) {
            return new self($error->code, $error->line, false, null, '', null, $raw, null, $raw);
        }

        $text = $match['text'];

        return new self(
            code: $error->code,
            line: $error->line,
            hasElement: true,
            namespace: $match['namespace'] !== '' || str_starts_with($raw, "Element '{") ? $match['namespace'] : null,
            element: $match['element'],
            attribute: $match['attribute'] !== '' ? $match['attribute'] : null,
            text: $text,
            value: self::valueOf($text),
            raw: $raw,
        );
    }

    public function duplicates(self $other): bool
    {
        return $this->hasElement
            && $this->value !== null
            && $this->code !== $other->code
            && $this->line === $other->line
            && $this->namespace === $other->namespace
            && $this->element === $other->element
            && $this->value === $other->value;
    }

    private static function valueOf(string $text): ?string
    {
        if (str_contains($text, self::EMPTY_LENGTH)) {
            return '';
        }

        return preg_match(self::VALUE, $text, $match) === 1 ? $match['value'] : null;
    }
}
