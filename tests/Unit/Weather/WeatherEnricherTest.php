<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Splitline\Core\Data\ActivityWeatherData;
use Splitline\Core\Data\RouteInput;
use Splitline\Core\Data\WeatherSampleData;
use Splitline\Core\Http\HttpRequest;
use Splitline\Core\Http\HttpResponse;
use Splitline\Core\Http\SlidingWindowRateLimiter;
use Splitline\Tests\Support\FakeHttpTransport;
use Splitline\Tests\Support\FakeSleeper;
use Splitline\Tests\Support\InMemoryCacheStore;
use Splitline\Weather\ActivityWaypointSampler;
use Splitline\Weather\OpenMeteoClient;
use Splitline\Weather\WeatherEnricher;
use Splitline\Weather\WindVectorMath;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<int>  $timestamps
 * @param  array<string, list<float|int|null>>  $overrides
 * @return array{hourly: array<string, list<float|int|null>>}
 */
function enricherOpenMeteoLocation(array $timestamps, array $overrides = []): array
{
    $count = count($timestamps);

    $defaults = [
        'time' => $timestamps,
        'temperature_2m' => array_fill(0, $count, 16.0),
        'relative_humidity_2m' => array_fill(0, $count, 70.0),
        'apparent_temperature' => array_fill(0, $count, 14.0),
        'dew_point_2m' => array_fill(0, $count, 10.5),
        'wind_speed_10m' => array_fill(0, $count, 14.0),
        'wind_gusts_10m' => array_fill(0, $count, 22.0),
        'wind_direction_10m' => array_fill(0, $count, 45.0),
        'weather_code' => array_fill(0, $count, 2),
        'precipitation' => array_fill(0, $count, 0.0),
        'cloud_cover' => array_fill(0, $count, 50.0),
        'surface_pressure' => array_fill(0, $count, 1015.0),
    ];

    return [
        'hourly' => array_merge($defaults, $overrides),
    ];
}

/**
 * @return list<array{time: float, distance: float, altitude: float, lat: float, lng: float}>
 */
function enricherNorthwardSeries(float $distanceMeters = 6000.0, float $durationSeconds = 1800.0, int $steps = 60): array
{
    $series = [];
    $latDeltaPerMeter = 1.0 / 111195.0;

    for ($i = 0; $i <= $steps; $i++) {
        $fraction = $i / $steps;
        $dist = $distanceMeters * $fraction;
        $series[] = [
            'time' => round($durationSeconds * $fraction, 1),
            'distance' => round($dist, 1),
            'altitude' => 40.0 + ($fraction * 25.0),
            'lat' => -36.8500 + ($dist * $latDeltaPerMeter),
            'lng' => 174.7633,
        ];
    }

    return $series;
}

it('determines eligibility for indoor, coordinate-less, start-point, and GNSS-stream routes', function (): void {
    $http = new FakeHttpTransport;
    $sleeper = new FakeSleeper;
    $client = new OpenMeteoClient($http, new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper), $sleeper);
    $enricher = new WeatherEnricher(new ActivityWaypointSampler, $client, new WindVectorMath);

    $startUtc = CarbonImmutable::parse('2026-11-21 06:00:00', 'UTC');

    $indoor = new RouteInput(
        isIndoor: true,
        startedAtUtc: $startUtc,
        durationSeconds: 1800.0,
        elapsedDurationSeconds: 1800.0,
        distanceMeters: 5000.0,
        elevationGain: null,
        startLat: -36.8500,
        startLng: 174.7633,
    );

    $missingCoords = new RouteInput(
        isIndoor: false,
        startedAtUtc: $startUtc,
        durationSeconds: 1800.0,
        elapsedDurationSeconds: 1800.0,
        distanceMeters: 5000.0,
        elevationGain: null,
        startLat: null,
        startLng: null,
        series: null,
    );

    $startPointOnly = new RouteInput(
        isIndoor: false,
        startedAtUtc: $startUtc,
        durationSeconds: 1800.0,
        elapsedDurationSeconds: 1800.0,
        distanceMeters: 5000.0,
        elevationGain: null,
        startLat: -36.8500,
        startLng: 174.7633,
        series: null,
    );

    $seriesOnly = new RouteInput(
        isIndoor: false,
        startedAtUtc: $startUtc,
        durationSeconds: 1800.0,
        elapsedDurationSeconds: 1800.0,
        distanceMeters: 5000.0,
        elevationGain: null,
        startLat: null,
        startLng: null,
        series: [
            ['time' => 0.0, 'distance' => 0.0, 'lat' => -36.8500, 'lng' => 174.7633],
        ],
    );

    expect($enricher->isEligible($indoor))->toBeFalse()
        ->and($enricher->isEligible($missingCoords))->toBeFalse()
        ->and($enricher->isEligible($startPointOnly))->toBeTrue()
        ->and($enricher->isEligible($seriesOnly))->toBeTrue()
        ->and($enricher->enrich($indoor))->toBeNull()
        ->and($enricher->enrich($missingCoords))->toBeNull();

    $http->assertSentCount(0);
});

it('enriches route-mode activities with hourly interpolation, wrapping wind vectors, Windy override, and route wind impact', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $startUtc = CarbonImmutable::parse('2026-11-21 00:30:00', 'UTC');
    $hour0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC')->getTimestamp();
    $hour1 = CarbonImmutable::parse('2026-11-21 01:00:00', 'UTC')->getTimestamp();
    $hour2 = CarbonImmutable::parse('2026-11-21 02:00:00', 'UTC')->getTimestamp();

    $http = (new FakeHttpTransport)->respondWith(static function (HttpRequest $request) use ($hour0, $hour1, $hour2): HttpResponse {
        $latParam = is_string($request->query['latitude'] ?? null) ? $request->query['latitude'] : '';
        $coordCount = count(explode(',', $latParam));

        $locations = [];

        for ($i = 0; $i < $coordCount; $i++) {
            $locations[] = enricherOpenMeteoLocation([$hour0, $hour1, $hour2], [
                'temperature_2m' => [14.0, 18.0, 20.0],
                'apparent_temperature' => [10.0, 14.0, 16.0],
                'wind_speed_10m' => [24.0, 24.0, 24.0],
                'wind_gusts_10m' => [36.0, 38.0, 40.0],
                'wind_direction_10m' => [355.0, 5.0, 5.0],
                'weather_code' => [2, 2, 2],
            ]);
        }

        return new HttpResponse(200, (string) json_encode($locations));
    });

    $sleeper = new FakeSleeper;
    $client = new OpenMeteoClient($http, new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper), $sleeper);
    $enricher = new WeatherEnricher(new ActivityWaypointSampler, $client, new WindVectorMath);

    $input = new RouteInput(
        isIndoor: false,
        startedAtUtc: $startUtc,
        durationSeconds: 1800.0,
        elapsedDurationSeconds: 1800.0,
        distanceMeters: 6000.0,
        elevationGain: 25.0,
        startLat: -36.8500,
        startLng: 174.7633,
        series: enricherNorthwardSeries(6000.0, 1800.0, 60),
    );

    $weather = $enricher->enrich($input);

    expect($weather)->toBeInstanceOf(ActivityWeatherData::class)
        ->and($weather?->provider)->toBe(WeatherEnricher::PROVIDER)
        ->and($weather?->modelEndpoint)->toBe('forecast')
        ->and($weather?->samplingMode)->toBe('route')
        ->and($weather?->waypointCount)->toBeGreaterThanOrEqual(3)
        ->and($weather?->wmoCode)->toBe(2)
        ->and($weather?->condition)->toBe('Windy')
        ->and($weather?->baseCondition)->toBe('Partly Cloudy')
        ->and($weather?->icon)->toBe('wind')
        ->and($weather?->windDirectionDeg)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(5)
        ->and($weather?->windDirectionCompass)->toBe('N')
        ->and($weather?->windGustsKmh)->toBe(38.0)
        ->and($weather?->headwindPct)->toBe(100.0)
        ->and($weather?->crosswindPct)->toBe(0.0)
        ->and($weather?->tailwindPct)->toBe(0.0)
        ->and($weather?->avgEffectiveWindKmh)->toBeLessThan(-23.0)
        ->and($weather?->samples)->toHaveCount($weather?->waypointCount ?? 0);

    // First sample at 00:30 UTC linearly interpolates 14.0 & 18.0 -> 16.0 C and circularly interpolates 355 & 5 -> 0 deg
    $firstSample = $weather?->samples[0];
    expect($firstSample)->toBeInstanceOf(WeatherSampleData::class)
        ->and($firstSample?->temperatureC)->toBe(16.0)
        ->and($firstSample?->apparentTemperatureC)->toBe(12.0)
        ->and($firstSample?->windDirectionDeg)->toBe(0)
        ->and($firstSample?->windDirectionCompass)->toBe('N');

    // Start time at 00:30 UTC pads start_date back by 1 hour to the previous UTC day
    expect($http->requests)->toHaveCount(1)
        ->and($http->requests[0]->query['start_date'])->toBe('2026-11-20')
        ->and($http->requests[0]->query['end_date'])->toBe('2026-11-21');
});

it('enriches start_point mode activities with hourly samples and null route wind impact', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $startUtc = CarbonImmutable::parse('2026-11-21 08:00:00', 'UTC');
    $hour0 = $startUtc->getTimestamp();
    $hour1 = $startUtc->addHour()->getTimestamp();
    $hour2 = $startUtc->addHours(2)->getTimestamp();

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, (string) json_encode(enricherOpenMeteoLocation([$hour0, $hour1, $hour2], [
            'temperature_2m' => [12.0, 15.0, 19.0],
            'wind_speed_10m' => [10.0, 10.0, 10.0],
            'wind_gusts_10m' => [15.0, 18.0, 20.0],
            'wind_direction_10m' => [180.0, 180.0, 180.0],
            'weather_code' => [1, 1, 1],
        ]))),
    );

    $sleeper = new FakeSleeper;
    $client = new OpenMeteoClient($http, new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper), $sleeper);
    $enricher = new WeatherEnricher(new ActivityWaypointSampler, $client, new WindVectorMath);

    $input = new RouteInput(
        isIndoor: false,
        startedAtUtc: $startUtc,
        durationSeconds: 5400.0, // 1.5h -> offsets 0, 3600, 5400
        elapsedDurationSeconds: 5400.0,
        distanceMeters: 9000.0,
        elevationGain: null,
        startLat: -36.8500,
        startLng: 174.7633,
        series: null,
    );

    $weather = $enricher->enrich($input);

    expect($weather)->toBeInstanceOf(ActivityWeatherData::class)
        ->and($weather?->samplingMode)->toBe('start_point')
        ->and($weather?->waypointCount)->toBe(3)
        ->and($weather?->condition)->toBe('Mostly Clear')
        ->and($weather?->baseCondition)->toBe('Mostly Clear')
        ->and($weather?->icon)->toBe('sun')
        ->and($weather?->minTemperatureC)->toBe(12.0)
        ->and($weather?->maxTemperatureC)->toBe(17.0) // 1.5h interpolates 15.0 & 19.0 -> 17.0
        ->and($weather?->temperatureC)->toBe(14.7) // mean(12, 15, 17) = 14.666... -> 14.7
        ->and($weather?->windDirectionDeg)->toBe(180)
        ->and($weather?->windDirectionCompass)->toBe('S')
        ->and($weather?->headwindPct)->toBeNull()
        ->and($weather?->crosswindPct)->toBeNull()
        ->and($weather?->tailwindPct)->toBeNull()
        ->and($weather?->avgEffectiveWindKmh)->toBeNull();

    // All 3 waypoints share one coordinate so Open-Meteo is queried with a single coordinate
    expect($http->requests)->toHaveCount(1)
        ->and($http->requests[0]->query['latitude'])->toBe('-36.85')
        ->and($http->requests[0]->query['longitude'])->toBe('174.7633');
});

it('resolves representative WMO code by frequency and breaks ties toward higher severity', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $startUtc = CarbonImmutable::parse('2026-11-21 08:00:00', 'UTC');
    $hour0 = $startUtc->getTimestamp();
    $hour1 = $startUtc->addHour()->getTimestamp();

    // 2 samples: one Clear (0) and one Rain (63) -> tie broken toward higher code (63 = Rain)
    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, (string) json_encode(enricherOpenMeteoLocation([$hour0, $hour1], [
            'weather_code' => [0, 63],
            'wind_speed_10m' => [10.0, 10.0],
            'wind_gusts_10m' => [15.0, 15.0],
        ]))),
    );

    $sleeper = new FakeSleeper;
    $client = new OpenMeteoClient($http, new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper), $sleeper);
    $enricher = new WeatherEnricher(new ActivityWaypointSampler, $client, new WindVectorMath);

    $input = new RouteInput(
        isIndoor: false,
        startedAtUtc: $startUtc,
        durationSeconds: 3600.0, // offsets 0 and 3600
        elapsedDurationSeconds: 3600.0,
        distanceMeters: 10000.0,
        elevationGain: null,
        startLat: -36.8500,
        startLng: 174.7633,
        series: null,
    );

    $weather = $enricher->enrich($input);

    expect($weather)->not->toBeNull()
        ->and($weather?->wmoCode)->toBe(63)
        ->and($weather?->condition)->toBe('Rain')
        ->and($weather?->icon)->toBe('cloud-rain');
});

it('returns null when start coordinates are out of range and no valid GNSS stream points exist', function (): void {
    $http = new FakeHttpTransport;
    $sleeper = new FakeSleeper;
    $client = new OpenMeteoClient($http, new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper), $sleeper);
    $enricher = new WeatherEnricher(new ActivityWaypointSampler, $client, new WindVectorMath);

    $invalidCoords = new RouteInput(
        isIndoor: false,
        startedAtUtc: CarbonImmutable::parse('2026-11-21 08:00:00', 'UTC'),
        durationSeconds: 1800.0,
        elapsedDurationSeconds: 1800.0,
        distanceMeters: 5000.0,
        elevationGain: null,
        startLat: 95.0,
        startLng: 200.0,
        series: null,
    );

    expect($enricher->enrich($invalidCoords))->toBeNull();
    $http->assertSentCount(0);
});
