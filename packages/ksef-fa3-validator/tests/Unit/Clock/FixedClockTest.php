<?php

declare(strict_types=1);

use KsefFa3\Clock\FixedClock;
use KsefFa3\Clock\SystemClock;

covers(FixedClock::class, SystemClock::class);

it('always returns the moment it was fixed at', function () {
    $clock = FixedClock::at('2026-11-10T23:30:00+01:00');

    expect($clock->now()->format(DATE_ATOM))->toBe('2026-11-10T23:30:00+01:00')
        ->and($clock->now())->toEqual($clock->now());
});

it('keeps the offset from the given timestamp', function () {
    expect(FixedClock::at('2026-06-01T00:00:00Z')->now()->getOffset())->toBe(0);
});

it('returns the current time from the system clock', function () {
    $before = new DateTimeImmutable;
    $now = (new SystemClock)->now();
    $after = new DateTimeImmutable;

    expect($now >= $before)->toBeTrue()
        ->and($now <= $after)->toBeTrue();
});
