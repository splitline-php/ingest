<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Splitline\Core\Support\PayloadCoercer;

it('coerces integers with rounding for floats and numeric strings', function (mixed $input, ?int $expected): void {
    expect(PayloadCoercer::asInt($input))->toBe($expected);
})->with([
    'positive int' => [42, 42],
    'negative int' => [-7, -7],
    'zero int' => [0, 0],
    'float rounds up' => [42.6, 43],
    'float rounds down' => [42.4, 42],
    'negative float rounds away from zero' => [-1.6, -2],
    'integer string' => ['123', 123],
    'float string' => ['99.5', 100],
    'non-numeric string' => ['abc', null],
    'empty string' => ['', null],
    'null' => [null, null],
    'boolean' => [true, null],
    'array' => [[1], null],
]);

it('coerces non-negative integers and rejects negative numbers', function (mixed $input, ?int $expected): void {
    expect(PayloadCoercer::asNonNegativeInt($input))->toBe($expected);
})->with([
    'zero' => [0, 0],
    'positive int' => [15, 15],
    'positive numeric string' => ['12.4', 12],
    'negative int' => [-1, null],
    'negative string' => ['-5', null],
    'negative float rounding to -1' => [-0.6, null],
    'null' => [null, null],
    'non-numeric' => ['nope', null],
]);

it('coerces floats from ints, floats, and numeric strings', function (mixed $input, ?float $expected): void {
    expect(PayloadCoercer::asFloat($input))->toBe($expected);
})->with([
    'float' => [3.14, 3.14],
    'int' => [5, 5.0],
    'numeric string' => ['2.5', 2.5],
    'negative numeric string' => ['-1.25', -1.25],
    'non-numeric string' => ['abc', null],
    'empty string' => ['', null],
    'null' => [null, null],
    'boolean' => [false, null],
    'array' => [[], null],
]);

it('coerces trimmed non-empty strings from strings, ints, and floats', function (mixed $input, ?string $expected): void {
    expect(PayloadCoercer::asString($input))->toBe($expected);
})->with([
    'trimmed string' => ['  hello  ', 'hello'],
    'empty string' => ['', null],
    'whitespace string' => [" \t\n ", null],
    'int' => [42, '42'],
    'float' => [3.5, '3.5'],
    'null' => [null, null],
    'boolean' => [true, null],
    'array' => [['a'], null],
]);

it('coerces arrays and rejects non-array values', function (): void {
    expect(PayloadCoercer::asArray([1, 2]))->toBe([1, 2])
        ->and(PayloadCoercer::asArray([]))->toBe([])
        ->and(PayloadCoercer::asArray(['a' => 1]))->toBe(['a' => 1])
        ->and(PayloadCoercer::asArray('not-an-array'))->toBeNull()
        ->and(PayloadCoercer::asArray(null))->toBeNull();
});

it('coerces associative arrays by keeping string keys and returning null when none exist', function (): void {
    expect(PayloadCoercer::asAssocArray(['a' => 1, 'b' => 2]))->toBe(['a' => 1, 'b' => 2])
        ->and(PayloadCoercer::asAssocArray([0 => 'ignored', 'keep' => 'yes']))->toBe(['keep' => 'yes'])
        ->and(PayloadCoercer::asAssocArray([]))->toBeNull()
        ->and(PayloadCoercer::asAssocArray([1, 2, 3]))->toBeNull()
        ->and(PayloadCoercer::asAssocArray('string'))->toBeNull()
        ->and(PayloadCoercer::asAssocArray(null))->toBeNull();
});

it('extracts leading YYYY-MM-DD date strings', function (mixed $input, ?string $expected): void {
    expect(PayloadCoercer::extractDateString($input))->toBe($expected);
})->with([
    'plain date' => ['2026-11-25', '2026-11-25'],
    'iso datetime' => ['2026-11-25T14:30:00Z', '2026-11-25'],
    'padded datetime' => ['  2026-11-25 08:00:00  ', '2026-11-25'],
    'day-first date' => ['25-11-2026', null],
    'invalid string' => ['not-a-date', null],
    'empty string' => ['', null],
    'null' => [null, null],
]);

it('converts local-offset-shifted epoch milliseconds without applying the process local timezone', function (): void {
    $previousTimezone = date_default_timezone_get();
    date_default_timezone_set('Pacific/Auckland');

    try {
        // 2026-11-25 08:30:15.500 UTC -> must NOT be shifted +13h to 21:30:15
        $epochMs = 1_795_595_415_500;
        $result = PayloadCoercer::timestampMsToLocal($epochMs);

        expect($result)->toBeInstanceOf(CarbonImmutable::class)
            ->and($result?->isUtc())->toBeTrue()
            ->and($result?->getOffset())->toBe(0)
            ->and($result?->format('Y-m-d H:i:s.v'))->toBe('2026-11-25 08:30:15.500')
            ->and(PayloadCoercer::timestampMsToLocal((string) $epochMs)?->format('Y-m-d H:i:s.v'))
            ->toBe('2026-11-25 08:30:15.500')
            ->and(PayloadCoercer::timestampMsToLocal(0))->toBeNull()
            ->and(PayloadCoercer::timestampMsToLocal(-1000))->toBeNull()
            ->and(PayloadCoercer::timestampMsToLocal(null))->toBeNull();
    } finally {
        date_default_timezone_set($previousTimezone);
    }
});

it('converts positive epoch milliseconds to UTC CarbonImmutable instances', function (): void {
    $epochMs = 1_795_595_415_250;
    $result = PayloadCoercer::timestampMsToUtc($epochMs);

    expect($result)->toBeInstanceOf(CarbonImmutable::class)
        ->and($result?->isUtc())->toBeTrue()
        ->and($result?->getOffset())->toBe(0)
        ->and($result?->format('Y-m-d H:i:s.v'))->toBe('2026-11-25 08:30:15.250')
        ->and(PayloadCoercer::timestampMsToUtc(0))->toBeNull()
        ->and(PayloadCoercer::timestampMsToUtc(-500))->toBeNull()
        ->and(PayloadCoercer::timestampMsToUtc('invalid'))->toBeNull()
        ->and(PayloadCoercer::timestampMsToUtc(null))->toBeNull();
});

it('parses datetime strings in the specified timezone and returns null on invalid input', function (): void {
    $parsed = PayloadCoercer::parseDatetime('2026-11-25 08:30:00', 'Pacific/Auckland');

    expect($parsed)->toBeInstanceOf(CarbonImmutable::class)
        ->and($parsed?->getTimezone()->getName())->toBe('Pacific/Auckland')
        ->and($parsed?->format('Y-m-d H:i:s'))->toBe('2026-11-25 08:30:00')
        ->and(PayloadCoercer::parseDatetime('', 'UTC'))->toBeNull()
        ->and(PayloadCoercer::parseDatetime(null, 'UTC'))->toBeNull()
        ->and(PayloadCoercer::parseDatetime('not-a-valid-datetime', 'UTC'))->toBeNull();
});

it('converts non-negative milliseconds to rounded seconds', function (mixed $input, ?int $expected): void {
    expect(PayloadCoercer::msToSeconds($input))->toBe($expected);
})->with([
    'zero' => [0, 0],
    'rounds down' => [1499, 1],
    'rounds up' => [1500, 2],
    'numeric string' => ['60000', 60],
    'negative' => [-1000, null],
    'null' => [null, null],
    'invalid' => ['abc', null],
]);

it('converts milliseconds to positive seconds and returns null when rounded result is zero or invalid', function (
    mixed $input,
    ?int $expected,
): void {
    expect(PayloadCoercer::positiveMsToSeconds($input))->toBe($expected);
})->with([
    'rounds up to 1s' => [500, 1],
    'rounds up to 2s' => [1500, 2],
    'rounds down to 0s' => [499, null],
    'zero' => [0, null],
    'negative' => [-1000, null],
    'null' => [null, null],
]);
