<?php

declare(strict_types=1);

use KsefFa3\Xsd\LibxmlMessage;

covers(LibxmlMessage::class);

it('splits the element prefix from the message', function (string $message, ?string $namespace, string $element, ?string $attribute, string $text) {
    $parsed = LibxmlMessage::parse(libxmlError(1871, $message, 12));

    expect($parsed->code)->toBe(1871)
        ->and($parsed->line)->toBe(12)
        ->and($parsed->hasElement)->toBeTrue()
        ->and($parsed->namespace)->toBe($namespace)
        ->and($parsed->element)->toBe($element)
        ->and($parsed->attribute)->toBe($attribute)
        ->and($parsed->text)->toBe($text)
        ->and($parsed->raw)->toBe($message);
})->with([
    'namespaced' => ["Element '{urn:a}P_2': This element is not expected.", 'urn:a', 'P_2', null, 'This element is not expected.'],
    'no namespace' => ["Element 'P_2': This element is not expected.", null, 'P_2', null, 'This element is not expected.'],
    'empty namespace' => ["Element '{}P_2': x", '', 'P_2', null, 'x'],
    'attribute' => ["Element '{urn:a}KodFormularza', attribute 'wersjaSchemy': The value '1-0' does not match.", 'urn:a', 'KodFormularza', 'wersjaSchemy', "The value '1-0' does not match."],
]);

it('keeps a message without an element prefix', function () {
    $parsed = LibxmlMessage::parse(libxmlError(3004, 'complex type: The content model is not determinist.'));

    expect($parsed->hasElement)->toBeFalse()
        ->and($parsed->element)->toBe('')
        ->and($parsed->namespace)->toBeNull()
        ->and($parsed->text)->toBe('complex type: The content model is not determinist.');
});

it('extracts the value from the message', function (int $code, string $text, ?string $value) {
    expect(LibxmlMessage::parse(libxmlError($code, "Element '{urn:a}X': ".$text))->value)->toBe($value);
})->with([
    'atomic type' => [1824, "'123,00' is not a valid value of the atomic type '{urn:a}TKwotowy'.", '123,00'],
    'value with a quote' => [1824, "'12'3' is not a valid value of the atomic type '{urn:a}TKwotowy'.", "12'3"],
    'empty atomic' => [1824, "'' is not a valid value of the local atomic type.", ''],
    'facet' => [1839, "[facet 'pattern'] The value 'PL1' is not accepted by the pattern '\\d'.", 'PL1'],
    'multiline facet' => [1840, "[facet 'enumeration'] The value 'a\nb' is not an element of the set {'1'}.", "a\nb"],
    'empty length' => [1831, "[facet 'minLength'] The value has a length of '0'; this underruns the allowed minimum length of '1'.", ''],
    'other length' => [1831, "[facet 'minLength'] The value has a length of '2'; this underruns the allowed minimum length of '10'.", null],
    'fixed' => [1874, "The value '1-0' does not match the fixed value constraint '1-0E'.", '1-0'],
    'structure' => [1871, 'This element is not expected.', null],
]);

it('treats consecutive reports of one value as duplicates', function (int $firstCode, string $first, int $secondCode, string $second, int $secondLine, bool $duplicate) {
    $a = LibxmlMessage::parse(libxmlError($firstCode, $first));
    $b = LibxmlMessage::parse(libxmlError($secondCode, $second, $secondLine));

    expect($a->duplicates($b))->toBe($duplicate)
        ->and($b->duplicates($a))->toBe($duplicate);
})->with([
    'fraction digits and pattern' => [1838, "Element 'P_14_1': [facet 'fractionDigits'] The value '1.001' has more", 1839, "Element 'P_14_1': [facet 'pattern'] The value '1.001' is not accepted", 7, true],
    'empty length and enumeration' => [1831, "Element 'P_12': [facet 'minLength'] The value has a length of '0'; x", 1840, "Element 'P_12': [facet 'enumeration'] The value '' is not an element", 7, true],
    'same code twice' => [1839, "Element 'NIP': [facet 'pattern'] The value 'PL1' is not", 1839, "Element 'NIP': [facet 'pattern'] The value 'PL1' is not", 7, false],
    'other line' => [1838, "Element 'P_14_1': [facet 'fractionDigits'] The value '1.001' has more", 1839, "Element 'P_14_1': [facet 'pattern'] The value '1.001' is not accepted", 8, false],
    'other element' => [1838, "Element 'P_14_1': [facet 'fractionDigits'] The value '1.001' has more", 1839, "Element 'P_14_2': [facet 'pattern'] The value '1.001' is not accepted", 7, false],
    'other namespace' => [1838, "Element '{urn:a}P_14_1': [facet 'fractionDigits'] The value '1.001' has more", 1839, "Element 'P_14_1': [facet 'pattern'] The value '1.001' is not accepted", 7, false],
    'other value' => [1838, "Element 'P_14_1': [facet 'fractionDigits'] The value '1.001' has more", 1839, "Element 'P_14_1': [facet 'pattern'] The value '1.002' is not accepted", 7, false],
    'no value' => [1871, "Element 'P_2': This element is not expected.", 1827, "Element 'P_2': The attribute 'a' is not allowed.", 7, false],
    'no element' => [3000, "The value '1' x", 3001, "The value '1' y", 7, false],
]);
