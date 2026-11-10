<?php

declare(strict_types=1);

use KsefFa3\Fa3Validator;
use KsefFa3\Report\Report;
use KsefFa3\Report\Result;
use KsefFa3\Stage\ParseStage;
use KsefFa3\Stage\PreflightStage;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

covers(Fa3Validator::class, PreflightStage::class, ParseStage::class);

const GARBAGE_SEED = 20261110;

/**
 * @return list<string>
 */
function garbageInputs(): array
{
    $random = new Randomizer(new Xoshiro256StarStar(GARBAGE_SEED));
    $sample = fixtureBytes('valid-minimal.xml');
    $inputs = [];

    for ($i = 0; $i < 300; $i++) {
        $inputs[] = match ($i % 4) {
            0 => $random->getBytes($random->getInt(0, 4096) ?: 1),
            1 => '%PDF-1.7'.$random->getBytes($random->getInt(0, 4096) ?: 1),
            2 => "PK\x03\x04".$random->getBytes($random->getInt(0, 4096) ?: 1),
            default => substr($sample, 0, $random->getInt(0, strlen($sample) - 1)),
        };
    }

    return $inputs;
}

it('reports errors for garbage without throwing or raising PHP warnings', function () {
    $validator = new Fa3Validator;

    set_error_handler(function (int $level, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $level, $file, $line);
    });

    try {
        foreach (garbageInputs() as $index => $bytes) {
            $report = $validator->validate($bytes);

            expect($report)->toBeInstanceOf(Report::class)
                ->and($report->result())->toBeIn([Result::Errors, Result::Unsupported], "input {$index}");
        }
    } finally {
        restore_error_handler();
    }
});

it('produces the same garbage for the same seed', function () {
    expect(garbageInputs())->toHaveCount(300)
        ->and(garbageInputs())->toBe(garbageInputs());
});

it('truncates a sample that passes when complete', function () {
    expect(validateBytes(fixtureBytes('valid-minimal.xml'))->result())->toBe(Result::Passed);
});
