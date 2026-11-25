<?php

declare(strict_types=1);

use Splitline\Core\Data\ActivityStreamData;

/**
 * @return array{
 *     time: float,
 *     distance: ?float,
 *     altitude: ?float,
 *     speed: ?float,
 *     hr: ?int,
 *     cadence: ?int,
 *     power: ?int,
 *     lat: ?float,
 *     lng: ?float
 * }
 */
function streamRecordWithHr(?int $hr, float $time = 0.0): array
{
    return [
        'time' => $time,
        'distance' => null,
        'altitude' => null,
        'speed' => null,
        'hr' => $hr,
        'cadence' => null,
        'power' => null,
        'lat' => null,
        'lng' => null,
    ];
}

it('detects whether the stream meets the minimum valid heart rate sample threshold', function (): void {
    $exactThreshold = new ActivityStreamData(records: [
        streamRecordWithHr(31, 0.0),
        streamRecordWithHr(120, 1.0),
        streamRecordWithHr(135, 2.0),
        streamRecordWithHr(145, 3.0),
        streamRecordWithHr(150, 4.0),
    ]);

    $fewerThanMinSamples = new ActivityStreamData(records: [
        streamRecordWithHr(120, 0.0),
        streamRecordWithHr(130, 1.0),
        streamRecordWithHr(140, 2.0),
        streamRecordWithHr(150, 3.0),
    ]);

    $belowMinBpmAndNull = new ActivityStreamData(records: [
        streamRecordWithHr(null, 0.0),
        streamRecordWithHr(0, 1.0),
        streamRecordWithHr(29, 2.0),
        streamRecordWithHr(30, 3.0),
        streamRecordWithHr(140, 4.0),
        streamRecordWithHr(145, 5.0),
        streamRecordWithHr(150, 6.0),
        streamRecordWithHr(155, 7.0),
    ]);

    expect($exactThreshold->hasHeartRateSamples())->toBeTrue()
        ->and($fewerThanMinSamples->hasHeartRateSamples())->toBeFalse()
        ->and($fewerThanMinSamples->hasHeartRateSamples(minSamples: 4))->toBeTrue()
        ->and($belowMinBpmAndNull->hasHeartRateSamples())->toBeFalse()
        ->and($belowMinBpmAndNull->hasHeartRateSamples(minSamples: 5, minBpm: 29))->toBeTrue()
        ->and((new ActivityStreamData)->hasHeartRateSamples())->toBeFalse();
});

it('calculates heart rate coverage ratio across empty, partial, full, and filtered streams', function (): void {
    $empty = new ActivityStreamData;

    $partial = new ActivityStreamData(records: [
        streamRecordWithHr(null, 0.0),
        streamRecordWithHr(25, 1.0),
        streamRecordWithHr(30, 2.0),
        streamRecordWithHr(140, 3.0),
        streamRecordWithHr(155, 4.0),
    ]);

    $full = new ActivityStreamData(records: [
        streamRecordWithHr(110, 0.0),
        streamRecordWithHr(145, 1.0),
        streamRecordWithHr(160, 2.0),
    ]);

    expect($empty->heartRateCoverageRatio())->toBe(0.0)
        ->and($partial->heartRateCoverageRatio())->toBe(0.4)
        ->and($partial->heartRateCoverageRatio(minBpm: 140))->toBe(0.2)
        ->and($partial->heartRateCoverageRatio(minBpm: 200))->toBe(0.0)
        ->and($full->heartRateCoverageRatio())->toBe(1.0);
});
