<?php

declare(strict_types=1);

use KsefFa3\Value\InvalidNip;
use KsefFa3\Value\Nip;

covers(Nip::class);

function nipChecksum(string $prefix): int
{
    $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    $sum = 0;

    foreach ($weights as $position => $weight) {
        $sum += (int) $prefix[$position] * $weight;
    }

    return $sum % 11;
}

dataset('valid nips', ['1111111111', '2222222222', '9999999999', '0000000000']);

dataset('invalid nips', [
    'wrong check digit' => '1111111112',
    'checksum equal to ten' => '0005000000',
    'too short' => '123456789',
    'too long' => '12345678901',
    'letter inside' => '11111111a1',
    'empty' => '',
    'leading space' => ' 1111111111',
    'trailing newline' => "1111111111\n",
]);

it('accepts a valid NIP', function (string $value) {
    expect(Nip::isValid($value))->toBeTrue()
        ->and(Nip::fromString($value)->value())->toBe($value);
})->with('valid nips');

it('rejects an invalid NIP', function (string $value) {
    expect(Nip::isValid($value))->toBeFalse();
})->with('invalid nips');

it('throws without revealing the value', function (string $value) {
    expect(fn () => Nip::fromString($value))->toThrow(function (InvalidNip $exception) use ($value) {
        expect($exception)->toBeInstanceOf(InvalidArgumentException::class);

        if ($value !== '') {
            expect($exception->getMessage())->not->toContain($value);
        }
    });
})->with('invalid nips');

it('accepts any prefix completed with its check digit', function () {
    mt_srand(20261110);
    $checked = 0;

    while ($checked < 500) {
        $prefix = str_pad((string) mt_rand(0, 999_999_999), 9, '0', STR_PAD_LEFT);
        $checksum = nipChecksum($prefix);

        if ($checksum === 10) {
            continue;
        }

        expect(Nip::isValid($prefix.$checksum))->toBeTrue();
        $checked++;
    }
});

it('rejects a valid NIP with any single digit changed', function () {
    mt_srand(20261111);
    $checked = 0;

    while ($checked < 100) {
        $prefix = str_pad((string) mt_rand(0, 999_999_999), 9, '0', STR_PAD_LEFT);
        $checksum = nipChecksum($prefix);

        if ($checksum === 10) {
            continue;
        }

        $valid = $prefix.$checksum;

        for ($position = 0; $position < 10; $position++) {
            for ($digit = 0; $digit <= 9; $digit++) {
                if ((string) $digit === $valid[$position]) {
                    continue;
                }

                $changed = substr_replace($valid, (string) $digit, $position, 1);
                expect(Nip::isValid($changed))->toBeFalse();
            }
        }

        $checked++;
    }
});
