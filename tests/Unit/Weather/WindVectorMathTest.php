<?php

declare(strict_types=1);

use Splitline\Weather\WindVectorMath;

it('calculates haversine distance and forward azimuth bearing between GNSS coordinates', function (): void {
    $math = new WindVectorMath;

    // 1 degree of latitude along a meridian is ~111.195 km
    $distanceMeters = $math->haversineMeters(0.0, 0.0, 1.0, 0.0);
    expect($distanceMeters)->toBeGreaterThan(111000.0)->toBeLessThan(111400.0);

    expect($math->forwardBearing(0.0, 0.0, 1.0, 0.0))->toEqualWithDelta(0.0, 0.1)
        ->and($math->forwardBearing(0.0, 0.0, 0.0, 1.0))->toEqualWithDelta(90.0, 0.1)
        ->and($math->forwardBearing(1.0, 0.0, 0.0, 0.0))->toEqualWithDelta(180.0, 0.1)
        ->and($math->forwardBearing(0.0, 1.0, 0.0, 0.0))->toEqualWithDelta(270.0, 0.1);
});

it('circularly interpolates wind vectors across the 355 to 5 degree wrap-around boundary', function (): void {
    $math = new WindVectorMath;

    $midpoint = $math->interpolateWindVector(20.0, 355.0, 20.0, 5.0, 0.5);

    expect($midpoint['direction_deg'])->toBe(0)
        ->and($midpoint['speed'])->toBeGreaterThan(19.9)->toBeLessThanOrEqual(20.0);

    $quarter = $math->interpolateWindVector(20.0, 350.0, 20.0, 10.0, 0.25);
    expect($quarter['direction_deg'])->toBe(355);

    $threeQuarter = $math->interpolateWindVector(20.0, 350.0, 20.0, 10.0, 0.75);
    expect($threeQuarter['direction_deg'])->toBe(5);

    // Opposite equal vectors cancel out cleanly to zero speed
    $cancelled = $math->interpolateWindVector(15.0, 90.0, 15.0, 270.0, 0.5);
    expect($cancelled['speed'])->toBe(0.0)
        ->and($cancelled['direction_deg'])->toBe(0);
});

it('computes vector mean wind across wrapping meteorological directions', function (): void {
    $math = new WindVectorMath;

    $mean = $math->vectorMeanWind([
        ['speed' => 18.0, 'direction_deg' => 350],
        ['speed' => 18.0, 'direction_deg' => 10],
    ]);

    expect($mean['direction_deg'])->toBe(0)
        ->and($mean['speed'])->toBeGreaterThan(17.7)->toBeLessThan(18.0);

    $meanFromSampleShape = $math->vectorMeanWind([
        ['wind_speed_kmh' => 24.0, 'wind_direction_deg' => 260],
        ['wind_speed_kmh' => 24.0, 'wind_direction_deg' => 280],
    ]);

    expect($meanFromSampleShape['direction_deg'])->toBe(270);

    expect($math->vectorMeanWind([]))->toBe(['speed' => 0.0, 'direction_deg' => 0]);
});

it('maps degree boundaries accurately across all 16 compass points', function (float $degrees, string $expected): void {
    $math = new WindVectorMath;

    expect($math->compassDirection($degrees))->toBe($expected);
})->with([
    'north zero' => [0.0, 'N'],
    'north upper bound' => [11.24, 'N'],
    'north-northeast lower bound' => [11.25, 'NNE'],
    'northeast' => [45.0, 'NE'],
    'east-northeast' => [67.5, 'ENE'],
    'east' => [90.0, 'E'],
    'southeast' => [135.0, 'SE'],
    'south' => [180.0, 'S'],
    'southwest' => [225.0, 'SW'],
    'west-southwest' => [247.5, 'WSW'],
    'west' => [270.0, 'W'],
    'northwest' => [315.0, 'NW'],
    'north-northwest upper bound' => [348.74, 'NNW'],
    'north wrap lower bound' => [348.75, 'N'],
    'north wrap 359.9' => [359.9, 'N'],
    'negative angle' => [-10.0, 'N'],
]);

it('maps WMO weather codes to labels and icon keys', function (int $code, string $condition, string $icon): void {
    $math = new WindVectorMath;

    expect($math->wmoCondition($code))->toBe([
        'condition' => $condition,
        'icon' => $icon,
    ]);
})->with([
    'clear' => [0, 'Clear', 'sun'],
    'mostly clear' => [1, 'Mostly Clear', 'sun'],
    'partly cloudy' => [2, 'Partly Cloudy', 'cloud-sun'],
    'overcast' => [3, 'Overcast', 'cloud'],
    'fog' => [45, 'Foggy', 'cloud-fog'],
    'rime fog' => [48, 'Foggy', 'cloud-fog'],
    'drizzle' => [53, 'Drizzle', 'cloud-drizzle'],
    'freezing drizzle' => [56, 'Freezing Drizzle', 'cloud-drizzle'],
    'light rain' => [61, 'Light Rain', 'cloud-rain'],
    'rain' => [63, 'Rain', 'cloud-rain'],
    'heavy rain' => [65, 'Heavy Rain', 'cloud-rain'],
    'freezing rain' => [66, 'Freezing Rain', 'cloud-rain'],
    'snow' => [73, 'Snow', 'snowflake'],
    'rain showers' => [81, 'Rain Showers', 'cloud-rain'],
    'snow showers' => [85, 'Snow Showers', 'snowflake'],
    'thunderstorm' => [95, 'Thunderstorm', 'cloud-lightning'],
    'unknown fallback' => [999, 'Unknown', 'cloud'],
]);

it('applies smart Windy override only to dry mild codes when wind or gusts exceed thresholds', function (): void {
    $math = new WindVectorMath;

    // Sustained wind >= 20 km/h on Partly Cloudy -> Windy
    expect($math->resolveDisplayCondition(2, 20.0, 28.0))->toBe([
        'condition' => 'Windy',
        'base_condition' => 'Partly Cloudy',
        'icon' => 'wind',
    ]);

    // Gusts >= 35 km/h on Clear even if sustained < 20 -> Windy
    expect($math->resolveDisplayCondition(0, 14.5, 35.0))->toBe([
        'condition' => 'Windy',
        'base_condition' => 'Clear',
        'icon' => 'wind',
    ]);

    // Below both thresholds -> retains base condition
    expect($math->resolveDisplayCondition(3, 19.9, 34.9))->toBe([
        'condition' => 'Overcast',
        'base_condition' => 'Overcast',
        'icon' => 'cloud',
    ]);

    // Precipitation and storm codes are never hidden behind Windy
    expect($math->resolveDisplayCondition(63, 38.0, 62.0))->toBe([
        'condition' => 'Rain',
        'base_condition' => 'Rain',
        'icon' => 'cloud-rain',
    ])->and($math->resolveDisplayCondition(95, 45.0, 70.0))->toBe([
        'condition' => 'Thunderstorm',
        'base_condition' => 'Thunderstorm',
        'icon' => 'cloud-lightning',
    ])->and($math->resolveDisplayCondition(45, 25.0, 40.0))->toBe([
        'condition' => 'Foggy',
        'base_condition' => 'Foggy',
        'icon' => 'cloud-fog',
    ]);
});

it('projects effective wind into headwind, crosswind, tailwind, and calm classifications', function (): void {
    $math = new WindVectorMath;

    // Wind from North (0 deg, blowing toward 180 deg) while traveling North (0 deg) => pure headwind
    expect($math->projectEffectiveWind(20.0, 0.0, 0.0))->toBe([
        'effective_kmh' => -20.0,
        'crosswind_kmh' => 0.0,
        'classification' => 'headwind',
    ]);

    // Wind from North (0 deg, blowing toward 180 deg) while traveling South (180 deg) => pure tailwind
    expect($math->projectEffectiveWind(20.0, 0.0, 180.0))->toBe([
        'effective_kmh' => 20.0,
        'crosswind_kmh' => 0.0,
        'classification' => 'tailwind',
    ]);

    // Wind from North (0 deg) while traveling East (90 deg) => pure crosswind
    expect($math->projectEffectiveWind(20.0, 0.0, 90.0))->toBe([
        'effective_kmh' => 0.0,
        'crosswind_kmh' => 20.0,
        'classification' => 'crosswind',
    ]);

    // Calm threshold (< 3 km/h)
    expect($math->projectEffectiveWind(2.9, 0.0, 0.0)['classification'])->toBe('calm');
});

it('aggregates distance-weighted route wind impact across GNSS streams', function (): void {
    $math = new WindVectorMath;

    // 4-leg square route: North 1 km, East 1 km, South 1 km, West 1 km (at 0 deg lat for near-equal legs)
    // Wind blowing from North (0 deg, 20 km/h):
    //   North leg => headwind (25%)
    //   East leg  => crosswind (25%)
    //   South leg => tailwind (25%)
    //   West leg  => crosswind (25%)
    $stepDeg = 0.0009; // ~100 m per point
    $series = [];
    $time = 0.0;
    $dist = 0.0;
    $lat = 0.0;
    $lng = 0.0;

    $legs = [
        [1, 0],   // North
        [0, 1],   // East
        [-1, 0],  // South
        [0, -1],  // West
    ];

    $series[] = ['time' => $time, 'distance' => $dist, 'lat' => $lat, 'lng' => $lng];

    foreach ($legs as [$dLatSign, $dLngSign]) {
        for ($i = 0; $i < 10; $i++) {
            $lat += $dLatSign * $stepDeg;
            $lng += $dLngSign * $stepDeg;
            $time += 25.0;
            $dist += 100.0;
            $series[] = ['time' => $time, 'distance' => $dist, 'lat' => $lat, 'lng' => $lng];
        }
    }

    $samples = [
        ['time_offset_s' => 0, 'distance_m' => 0.0, 'wind_speed_kmh' => 20.0, 'wind_direction_deg' => 0],
        ['time_offset_s' => (int) $time, 'distance_m' => $dist, 'wind_speed_kmh' => 20.0, 'wind_direction_deg' => 0],
    ];

    $impact = $math->computeRouteWindImpact($series, $samples);

    expect($impact['headwind_pct'])->toBeGreaterThan(20.0)->toBeLessThan(30.0)
        ->and($impact['tailwind_pct'])->toBeGreaterThan(20.0)->toBeLessThan(30.0)
        ->and($impact['crosswind_pct'])->toBeGreaterThan(45.0)->toBeLessThan(55.0)
        ->and(round(($impact['headwind_pct'] ?? 0.0) + ($impact['crosswind_pct'] ?? 0.0) + ($impact['tailwind_pct'] ?? 0.0), 1))->toBe(100.0)
        ->and($impact['avg_effective_wind_kmh'])->toEqualWithDelta(0.0, 0.5);

    // Calm wind returns 0% buckets and ~0 effective wind
    $calmImpact = $math->computeRouteWindImpact($series, [
        ['time_offset_s' => 0, 'distance_m' => 0.0, 'wind_speed_kmh' => 1.5, 'wind_direction_deg' => 0],
    ]);

    expect($calmImpact['headwind_pct'])->toBe(0.0)
        ->and($calmImpact['crosswind_pct'])->toBe(0.0)
        ->and($calmImpact['tailwind_pct'])->toBe(0.0);

    // Insufficient GNSS data returns nulls
    expect($math->computeRouteWindImpact([], $samples))->toBe([
        'headwind_pct' => null,
        'crosswind_pct' => null,
        'tailwind_pct' => null,
        'avg_effective_wind_kmh' => null,
    ]);
});
