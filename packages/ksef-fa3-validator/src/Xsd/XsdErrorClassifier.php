<?php

declare(strict_types=1);

namespace KsefFa3\Xsd;

use DOMElement;
use KsefFa3\Catalog\FieldLabels;
use KsefFa3\Xml\InvoiceDocument;
use LibXMLError;

final readonly class XsdErrorClassifier
{
    private const int STRUCTURE = 1871;

    private const int SHOWN_SET = 20;

    private const int VALUE_LENGTH = 60;

    private const int TRUNCATED_EXPECTED = 10;

    private const int COMPARED_CANDIDATES = 20;

    private const array DECIMAL_TYPES = ['TKwotowy', 'TKwotowy2', 'TIlosci', 'TProcentowy', 'TKwota2', 'TRzeczywisty'];

    private const array DATE_TYPES = ['TData', 'TDataT', 'TDataU'];

    private const array DATE_TIME_TYPES = ['TDataCzas'];

    private const array DATE_TIME_ELEMENTS = ['DataWytworzeniaFa'];

    private const array INTEGER_TYPES = [
        'TNaturalny', 'TCalkowity', 'TFormaPlatnosci', 'TLadunek', 'TRachunekWlasnyBanku', 'TStatusInfoPodatnika',
        'TRolaPodmiotuUpowaznionego', 'TRolaPodmiotu3', 'TTypKorekty', 'TRodzajTransportu', 'TWybor1', 'TWybor1_2', 'TWybor1_3',
    ];

    private const array VALUE_CODES = [1838, 1839, 1840, 1833, 1834, 1835, 1874];

    private const string ATOMIC = "/^'.*' is not a valid value of the (?<local>local )?atomic type(?: '(?:\{[^}]*\}|\w+:)?(?<type>[^']+)')?\.$/sD";

    private const string EXPECTED = "/^(?<kind>This element is not expected|Missing child element\(s\))\. Expected is (?:one of )?\( (?<expected>.+) \)\.$/sD";

    public function __construct(private FieldLabels $labels, private SchemaModel $model) {}

    public static function fromDirectories(string $fieldsDirectory, string $schemaDirectory): self
    {
        return new self(FieldLabels::fromFile($fieldsDirectory.'/labels.yaml'), SchemaModel::fromDirectory($schemaDirectory));
    }

    public function classify(LibXMLError $error, InvoiceDocument $invoice): XsdError
    {
        $message = LibxmlMessage::parse($error);

        if (! $message->hasElement) {
            return self::unmapped($message, null);
        }

        return $message->code === self::STRUCTURE ? $this->structure($message, $invoice) : $this->content($message, $invoice);
    }

    private function content(LibxmlMessage $message, InvoiceDocument $invoice): XsdError
    {
        $field = $this->locate($message, $invoice);
        $name = $message->element;
        $value = $message->value;
        $text = $message->text;

        if ($value === '' && in_array($message->code, [1824, 1831, 1839, 1840], true)) {
            return $this->found('XSD-EMPTY', $message, $field, hints: [$name]);
        }

        if ($value === null && in_array($message->code, self::VALUE_CODES, true)) {
            return self::unmapped($message, $field);
        }

        $shown = ['value' => self::shorten((string) $value)];

        return match (true) {
            $message->code === 1824 => $this->atomic($message, $field),
            $message->code === 1838 && preg_match("/allowed \('(?<max>\d+)'\)\.$/D", $text, $match) === 1 => $this->found('XSD-DECIMAL-PRECISION', $message, $field, [...$shown, 'max' => $match['max']], [$name, 'max-'.$match['max']]),
            $message->code === 1839 && $name === 'NIP' => $this->found('XSD-NIP-FORMAT', $message, $field, $shown),
            $message->code === 1839 && str_contains($text, "the pattern '-?([1-9]") => $this->found('XSD-DECIMAL-FORMAT', $message, $field, $shown, self::withVariant(self::decimalVariant((string) $value), $name)),
            $message->code === 1839 => $this->found('XSD-PATTERN', $message, $field, $shown, [$name]),
            $message->code === 1840 && preg_match('/is not an element of the set \{(?<set>.*)\}\.$/sD', $text, $match) === 1 => $this->enumeration($message, $field, $match['set']),
            $message->code === 1831 && preg_match("/has a length of '(?<length>\d+)'; this underruns the allowed minimum length of '(?<min>\d+)'/", $text, $match) === 1 => $this->found('XSD-MIN-LENGTH', $message, $field, ['length' => $match['length'], 'min' => $match['min']], [$name]),
            $message->code === 1832 && preg_match("/has a length of '(?<length>\d+)'; this exceeds the allowed maximum length of '(?<max>\d+)'/", $text, $match) === 1 => $this->found('XSD-MAX-LENGTH', $message, $field, ['length' => $match['length'], 'max' => $match['max']], [$name]),
            in_array($message->code, [1833, 1834], true) && preg_match("/value allowed \('(?<limit>.*)'\)\.$/sD", $text, $match) === 1 => $this->bound($message, $field, $match['limit']),
            $message->code === 1835 && preg_match("/must be greater than '(?<limit>.*)'\.$/sD", $text, $match) === 1 => $this->found('XSD-MIN-VALUE', $message, $field, [...$shown, 'limit' => $match['limit']], [$name, 'exclusive']),
            $message->code === 1868 && preg_match("/^The attribute '(?<attribute>[^']+)' is required but missing\.$/D", $text, $match) === 1 => $this->found('XSD-ATTRIBUTE-MISSING', $message, $field, ['attribute' => $match['attribute']], [$name]),
            $message->code === 1827 && $message->attribute !== null => $this->found('XSD-ATTRIBUTE-NOT-ALLOWED', $message, $field, ['attribute' => $message->attribute], [$name]),
            $message->code === 1828 => $this->found('XSD-CHILD-NOT-ALLOWED', $message, $field, hints: [$name]),
            $message->code === 1843 => $this->found('XSD-TEXT-NOT-ALLOWED', $message, $field, hints: [$name]),
            $message->code === 1874 && preg_match("/fixed value constraint '(?<fixed>.*)'\.$/sD", $text, $match) === 1 => $this->found('XSD-FIXED-VALUE', $message, $field, [...$shown, 'fixed' => $match['fixed'], ...($message->attribute === null ? [] : ['attribute' => $message->attribute])]),
            default => self::unmapped($message, $field),
        };
    }

    private function atomic(LibxmlMessage $message, string $field): XsdError
    {
        $name = $message->element;
        $value = (string) $message->value;
        $shown = ['value' => self::shorten($value)];

        preg_match(self::ATOMIC, $message->text, $match);
        $type = $match['type'] ?? '';

        return match (true) {
            in_array($type, self::DECIMAL_TYPES, true) => $this->found('XSD-DECIMAL-FORMAT', $message, $field, $shown, self::withVariant(self::decimalVariant($value), $name)),
            in_array($type, self::DATE_TYPES, true) => $this->found('XSD-DATE-FORMAT', $message, $field, $shown, self::withVariant(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 ? 'nonexistent' : null, $name)),
            in_array($type, self::DATE_TIME_TYPES, true), ($match['local'] ?? '') !== '' && in_array($name, self::DATE_TIME_ELEMENTS, true) => $this->found('XSD-DATE-FORMAT', $message, $field, $shown, [$name]),
            in_array($type, self::INTEGER_TYPES, true) => $this->found('XSD-INTEGER-FORMAT', $message, $field, $shown, [$name]),
            default => self::unmapped($message, $field),
        };
    }

    private function enumeration(LibxmlMessage $message, string $field, string $set): XsdError
    {
        preg_match_all("/'([^']*)'/", $set, $matches);
        $allowed = $matches[1];
        $shown = ['value' => self::shorten((string) $message->value)];

        return count($allowed) <= self::SHOWN_SET
            ? $this->found('XSD-ENUM', $message, $field, [...$shown, 'allowed' => implode(', ', $allowed)], [$message->element])
            : $this->found('XSD-ENUM', $message, $field, $shown, [$message->element, 'long-list']);
    }

    private function bound(LibxmlMessage $message, string $field, string $limit): XsdError
    {
        $name = $message->element;
        $params = ['value' => self::shorten((string) $message->value), 'limit' => $limit];
        $isDate = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $message->value) === 1;
        $isLower = $message->code === 1833;

        return match (true) {
            $isDate => $this->found('XSD-DATE-RANGE', $message, $field, $params, [$name, $isLower ? 'too-early' : 'too-late']),
            $isLower => $this->found('XSD-MIN-VALUE', $message, $field, $params, [$name, 'inclusive']),
            default => $this->found('XSD-MAX-VALUE', $message, $field, $params, [$name, 'inclusive']),
        };
    }

    /**
     * @param  array<string, string>  $params
     * @param  list<string>  $hints
     */
    private function found(string $id, LibxmlMessage $message, string $field, array $params = [], array $hints = []): XsdError
    {
        return new XsdError($id, $field, $message->line, ['field' => $message->element, 'label' => $this->labels->for($field), ...$params], $hints);
    }

    private function structure(LibxmlMessage $message, InvoiceDocument $invoice): XsdError
    {
        $candidates = $invoice->elementsAt($message->line, $message->element, $message->namespace);

        if (preg_match(self::EXPECTED, $message->text, $match) !== 1 || $candidates === []) {
            return self::unmapped($message, $this->locate($message, $invoice));
        }

        $expected = array_map(
            fn (string $name): string => (string) preg_replace('/^\{[^}]*\}/', '', $name),
            explode(', ', $match['expected']),
        );
        $missingChild = $match['kind'] !== 'This element is not expected';
        $results = [];

        foreach (array_slice($candidates, 0, self::COMPARED_CANDIDATES) as $candidate) {
            $result = $this->structureOf($candidate, $missingChild, $expected, $message, $invoice);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        if ($results === []) {
            return self::unmapped($message, $this->locate($message, $invoice));
        }

        $plausible = array_values(array_filter($results, fn (array $result): bool => $result[2]));
        $results = $plausible === [] ? $results : $plausible;
        $best = max(array_column($results, 1));
        $chosen = array_values(array_filter($results, fn (array $result): bool => $result[1] === $best));
        $error = $chosen[0][0];

        if (count($chosen) === 1 && count($candidates) <= self::COMPARED_CANDIDATES) {
            return $error;
        }

        $name = $error->params['field'];

        return new XsdError($error->id, $name, $error->line, [...$error->params, 'label' => $this->labels->for($name)], $error->hints);
    }

    /**
     * @param  list<string>  $expected
     * @return array{XsdError, int, bool}|null
     */
    private function structureOf(DOMElement $candidate, bool $missingChild, array $expected, LibxmlMessage $message, InvoiceDocument $invoice): ?array
    {
        $parent = $missingChild ? $candidate : $candidate->parentNode;

        if (! $parent instanceof DOMElement) {
            return null;
        }

        $children = $this->model->children(self::modelPath($parent));
        $parentField = $invoice->fieldPath($parent);

        if (! $missingChild) {
            $element = $this->elementStructure($candidate, $children, $expected, $parentField, $message->line, $invoice);

            if ($element !== null) {
                return $element;
            }
        }

        $previous = $missingChild ? $parent->lastElementChild : $candidate->previousElementSibling;
        $missing = self::missingName($children, $previous, $missingChild ? null : $candidate, $expected);
        $field = ltrim($parentField.'/'.$missing, '/');

        return [
            new XsdError('XSD-MISSING-ELEMENT', $field, $message->line, [
                'field' => $missing,
                'label' => $this->labels->for($field),
                'parent' => $this->labels->for($invoice->fieldOf($parent)),
            ], [$missing]),
            1,
            self::childNamed($parent, $missing) === null,
        ];
    }

    /**
     * @param  list<array{string, bool}>|null  $children
     * @param  list<string>  $expected
     * @return array{XsdError, int, bool}|null
     */
    private function elementStructure(DOMElement $element, ?array $children, array $expected, string $parentField, int $line, InvoiceDocument $invoice): ?array
    {
        $name = (string) $element->localName;
        $field = $invoice->fieldOf($element);
        $params = ['field' => $name, 'label' => $this->labels->for($field)];
        $known = $children === null ? $this->model->declares($name) : in_array($name, array_column($children, 0), true);
        $previous = $element->previousElementSibling;

        if ($element->namespaceURI !== InvoiceDocument::FA3_NAMESPACE || ! $known) {
            $id = $known ? 'XSD-WRONG-NAMESPACE' : 'XSD-UNEXPECTED-ELEMENT';

            return [new XsdError($id, $field, $line, $params, $known && $element->namespaceURI === null ? ['no-namespace'] : []), 2, true];
        }

        if ($previous !== null && $previous->localName === $name && $previous->namespaceURI === $element->namespaceURI) {
            return [new XsdError('XSD-ELEMENT-REPEATED', $field, $line, $params), 3, true];
        }

        for ($next = $element->nextElementSibling; $next !== null; $next = $next->nextElementSibling) {
            if ($next->namespaceURI === InvoiceDocument::FA3_NAMESPACE && in_array($next->localName, $expected, true)) {
                $params['expected'] = $this->labels->for(ltrim($parentField.'/'.$next->localName, '/'));

                return [new XsdError('XSD-ELEMENT-ORDER', $field, $line, $params), 2, true];
            }
        }

        return null;
    }

    /**
     * @param  list<array{string, bool}>|null  $children
     * @param  list<string>  $expected
     */
    private static function missingName(?array $children, ?DOMElement $previous, ?DOMElement $end, array $expected): string
    {
        $children ??= [];
        $names = array_column($children, 0);
        $start = $previous === null ? -1 : array_search($previous->localName, $names, true);
        $start = $start === false ? -1 : $start;
        $stop = count($names);

        if ($end !== null) {
            for ($index = $start + 1; $index < count($names); $index++) {
                if ($names[$index] === $end->localName) {
                    $stop = $index;
                    break;
                }
            }
        }

        // libxml cuts the list of expected elements at ten without saying so.
        $truncated = count($expected) >= self::TRUNCATED_EXPECTED;

        for ($index = $start + 1; $index < $stop; $index++) {
            [$name, $required] = $children[$index];

            if ($required && ($truncated || in_array($name, $expected, true))) {
                return $name;
            }
        }

        return $expected[0];
    }

    private function locate(LibxmlMessage $message, InvoiceDocument $invoice): string
    {
        $candidates = $invoice->elementsAt($message->line, $message->element, $message->namespace);

        if (count($candidates) > 1 && $message->value !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                fn (DOMElement $element): bool => self::collapse($element->textContent) === self::collapse((string) $message->value),
            ));
        }

        return count($candidates) === 1 ? $invoice->fieldOf($candidates[0]) : $message->element;
    }

    /**
     * @return list<string>
     */
    private static function modelPath(DOMElement $element): array
    {
        $path = [];

        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            array_unshift($path, (string) $node->localName);
        }

        return $path;
    }

    private static function childNamed(DOMElement $parent, string $name): ?DOMElement
    {
        for ($child = $parent->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if ($child->localName === $name && $child->namespaceURI === InvoiceDocument::FA3_NAMESPACE) {
                return $child;
            }
        }

        return null;
    }

    private static function decimalVariant(string $value): ?string
    {
        return match (true) {
            str_contains($value, ',') => 'comma',
            preg_match('/\s/u', $value) === 1 => 'space',
            str_starts_with($value, '+') => 'plus',
            preg_match('/^-?0\d/', $value) === 1 => 'leading-zero',
            str_ends_with($value, '.') => 'trailing-dot',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private static function withVariant(?string $variant, string $name): array
    {
        return $variant === null ? [$name] : [$variant, $name];
    }

    private static function shorten(string $value): string
    {
        return mb_strlen($value) > self::VALUE_LENGTH ? mb_substr($value, 0, self::VALUE_LENGTH - 1).'…' : $value;
    }

    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function unmapped(LibxmlMessage $message, ?string $field): XsdError
    {
        return new XsdError('XSD-UNMAPPED', $field, $message->line, ['detail' => $message->raw]);
    }
}
