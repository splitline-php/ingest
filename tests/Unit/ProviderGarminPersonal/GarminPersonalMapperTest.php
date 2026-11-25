<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Splitline\Core\Data\DailyHealthMetricData;
use Splitline\Core\Data\FitnessAgeData;
use Splitline\Core\Data\HeartRateZoneData;
use Splitline\Core\Data\HrvData;
use Splitline\Core\Data\NormalizedActivity;
use Splitline\Core\Data\ProviderContext;
use Splitline\Core\Data\SleepData;
use Splitline\Core\Data\Vo2MaxData;
use Splitline\Core\Data\WeightGoalData;
use Splitline\Core\Data\WeightMeasurementData;
use Splitline\Core\Exceptions\ProviderException;
use Splitline\Core\Http\HttpRequest;
use Splitline\Core\Http\HttpResponse;
use Splitline\Core\Http\SlidingWindowRateLimiter;
use Splitline\ProviderGarminPersonal\GarminPersonalClient;
use Splitline\ProviderGarminPersonal\GarminPersonalConfig;
use Splitline\ProviderGarminPersonal\GarminPersonalMapper;
use Splitline\ProviderGarminPersonal\GarminPersonalProvider;
use Splitline\ProviderGarminPersonal\GarminPersonalTokenManager;
use Splitline\Tests\Support\FakeHttpTransport;
use Splitline\Tests\Support\FakeSleeper;
use Splitline\Tests\Support\InMemoryCacheStore;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array<int|string, mixed>
 */
function loadGarminFixture(string $filename): array
{
    $path = __DIR__.'/../../Fixtures/GarminPersonal/'.$filename;
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Unable to read fixture: {$path}");
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("Fixture {$filename} did not decode to an array.");
    }

    return $decoded;
}

function makeGarminProviderWithResponder(Closure $responder): GarminPersonalProvider
{
    $config = new GarminPersonalConfig;
    $http = new FakeHttpTransport(responder: $responder);
    $cache = new InMemoryCacheStore;
    $sleeper = new FakeSleeper(static function (int $ms): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds($ms));
    });
    $rateLimiter = new SlidingWindowRateLimiter($cache, $sleeper);
    $tokenManager = new GarminPersonalTokenManager($http, $cache, $config);
    $client = new GarminPersonalClient($tokenManager, $http, $cache, $rateLimiter, $sleeper, $config);

    return new GarminPersonalProvider($client, new GarminPersonalMapper);
}

function mapperFakeJwt(): string
{
    $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'exp' => CarbonImmutable::now()->addHours(24)->getTimestamp(),
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return "{$header}.{$payload}.sig";
}

it('maps running, road biking, generic cycling, and mtb activities into NormalizedActivity DTOs', function (): void {
    $mapper = new GarminPersonalMapper;
    $activities = loadGarminFixture('activities.json');

    /** @var array<string, mixed> $runRaw */
    $runRaw = $activities[0];
    $run = $mapper->mapActivity($runRaw, 'Pacific/Auckland');

    expect($run)->toBeInstanceOf(NormalizedActivity::class)
        ->and($run?->provider)->toBe('garmin_personal')
        ->and($run?->externalId)->toBe('9001')
        ->and($run?->name)->toBe('Park Tempo Run')
        ->and($run?->activityTypeKey)->toBe('running')
        ->and($run?->sportCategory)->toBe('running')
        ->and($run?->sportDiscipline)->toBe('road')
        ->and($run?->isIndoor)->toBeFalse()
        ->and($run?->startedAtLocal->format('Y-m-d H:i:s'))->toBe('2026-11-21 08:00:00')
        ->and($run?->startedAtUtc->format('Y-m-d H:i:s'))->toBe('2026-11-20 19:00:00')
        ->and($run?->locationName)->toBe('Sample City')
        ->and($run?->startLat)->toBe(-43.5309)
        ->and($run?->startLng)->toBe(172.6362)
        ->and($run?->distanceMeters)->toBe(10050.5)
        ->and($run?->durationSeconds)->toBe(2710.0)
        ->and($run?->avgCadence)->toBe(172.5)
        ->and($run?->maxCadence)->toBe(184.0)
        ->and($run?->avgGradeAdjustedSpeed)->toBe(3.76)
        ->and($run?->bmrCalories)->toBe(85)
        ->and($run?->sweatLossMl)->toBe(620)
        ->and($run?->runDurationSeconds)->toBe(2640.46)
        ->and($run?->walkDurationSeconds)->toBe(45.12)
        ->and($run?->idleDurationSeconds)->toBe(24.42)
        ->and($run?->isFavorite)->toBeTrue()
        ->and($run?->isPr)->toBeFalse()
        ->and($run?->isManual)->toBeFalse()
        ->and($run?->rawSummary)->toBe($runRaw)
        ->and($run?->seededSplits)->toBe([
            ['distance_key' => '1k', 'distance_meters' => 1000.0, 'elapsed_seconds' => 255.0, 'source' => 'garmin_split'],
            ['distance_key' => '1mi', 'distance_meters' => 1609.34, 'elapsed_seconds' => 418.0, 'source' => 'garmin_split'],
            ['distance_key' => '5k', 'distance_meters' => 5000.0, 'elapsed_seconds' => 1320.0, 'source' => 'garmin_split'],
            ['distance_key' => '10k', 'distance_meters' => 10000.0, 'elapsed_seconds' => 2680.0, 'source' => 'garmin_split'],
        ]);

    /** @var array<string, mixed> $roadRaw */
    $roadRaw = $activities[1];
    $road = $mapper->mapActivity($roadRaw, 'Pacific/Auckland');

    expect($road)->toBeInstanceOf(NormalizedActivity::class)
        ->and($road?->sportCategory)->toBe('biking')
        ->and($road?->sportDiscipline)->toBe('road')
        ->and($road?->avgCadence)->toBe(84.0)
        ->and($road?->maxCadence)->toBe(112.0)
        ->and($road?->normalizedPower)->toBe(235.0)
        ->and($road?->ftp)->toBe(274.0)
        ->and($road?->tss)->toBe(118.4)
        ->and($road?->intensityFactor)->toBe(0.86)
        ->and($road?->leftBalance)->toBe(49.2)
        ->and($road?->powerCurve)->toBe([1 => 890.0, 60 => 410.0, 1200 => 264.0])
        ->and($road?->isPr)->toBeTrue()
        ->and($road?->seededSplits)->toBe([
            ['distance_key' => '40k', 'distance_meters' => 40000.0, 'elapsed_seconds' => 4780.0, 'source' => 'garmin_split'],
        ]);

    // Generic cycling STILL emits fastestSplit_40000 so host apps can evaluate discipline_override
    /** @var array<string, mixed> $genericRideRaw */
    $genericRideRaw = $activities[2];
    $genericRide = $mapper->mapActivity($genericRideRaw, 'Pacific/Auckland');

    expect($genericRide)->toBeInstanceOf(NormalizedActivity::class)
        ->and($genericRide?->sportCategory)->toBe('biking')
        ->and($genericRide?->sportDiscipline)->toBe('other')
        ->and($genericRide?->isManual)->toBeTrue()
        ->and($genericRide?->seededSplits)->toBe([
            ['distance_key' => '40k', 'distance_meters' => 40000.0, 'elapsed_seconds' => 4890.0, 'source' => 'garmin_split'],
        ]);

    /** @var array<string, mixed> $mtbRaw */
    $mtbRaw = $activities[3];
    $mtb = $mapper->mapActivity($mtbRaw, 'Pacific/Auckland');

    expect($mtb)->toBeInstanceOf(NormalizedActivity::class)
        ->and($mtb?->sportCategory)->toBe('biking')
        ->and($mtb?->sportDiscipline)->toBe('mtb')
        ->and($mtb?->grit)->toBe(24.5)
        ->and($mtb?->avgFlow)->toBe(2.8)
        ->and($mtb?->jumpCount)->toBe(12)
        ->and($mtb?->unpavedPct)->toBe(80.0);

    // Invalid activity ID or missing timestamps return null
    expect($mapper->mapActivity(['activityId' => 0], 'UTC'))->toBeNull()
        ->and($mapper->mapActivity(['activityId' => 1234], 'UTC'))->toBeNull();
});

it('maps v1 daily summary and skips empty days', function (): void {
    $mapper = new GarminPersonalMapper;
    /** @var array<string, mixed> $raw */
    $raw = loadGarminFixture('daily_summary_v1.json');

    $dto = $mapper->mapDailySummary($raw, '2026-11-21');

    expect($dto)->toBeInstanceOf(DailyHealthMetricData::class)
        ->and($dto?->provider)->toBe('garmin_personal')
        ->and($dto?->calendarDate)->toBe('2026-11-21')
        ->and($dto?->dayDurationSeconds)->toBe(162000)
        ->and($dto?->steps)->toBe(9420)
        ->and($dto?->stepGoal)->toBe(8000)
        ->and($dto?->distanceMeters)->toBe(7650.0)
        ->and($dto?->totalCalories)->toBe(2450)
        ->and($dto?->activeCalories)->toBe(620)
        ->and($dto?->bmrCalories)->toBe(1830)
        ->and($dto?->restingHr)->toBe(47)
        ->and($dto?->sevenDayAvgRhr)->toBe(48)
        ->and($dto?->avgStress)->toBe(24)
        ->and($dto?->bodyBatterySleep)->toBe(58)
        ->and($dto?->floorsAscended)->toBe(14.2)
        ->and($dto?->avgSpo2)->toBe(96.0)
        ->and($dto?->partialUpdate)->toBeFalse()
        ->and($dto?->fillOnlyWhenNullFields)->toBe([])
        ->and($dto?->rawPayload)->toBe($raw);

    expect($mapper->mapDailySummary(['calendarDate' => '2026-11-20', 'totalSteps' => 0], '2026-11-20'))->toBeNull();
});

it('maps v2 daily summaries with fillOnlyWhenNullFields protecting seven_day_avg_rhr', function (): void {
    $mapper = new GarminPersonalMapper;
    /** @var array<string, mixed> $response */
    $response = loadGarminFixture('daily_summaries_v2.json');

    $dtos = $mapper->mapDailySummariesV2($response);

    expect($dtos)->toHaveCount(1)
        ->and($dtos[0]->provider)->toBe('garmin_personal')
        ->and($dtos[0]->calendarDate)->toBe('2026-11-21')
        ->and($dtos[0]->dayDurationSeconds)->toBe(147600)
        ->and($dtos[0]->steps)->toBe(13098)
        ->and($dtos[0]->restingHr)->toBe(47)
        ->and($dtos[0]->sevenDayAvgRhr)->toBeNull()
        ->and($dtos[0]->restStressSeconds)->toBe(41760)
        ->and($dtos[0]->lowStressSeconds)->toBe(7380)
        ->and($dtos[0]->mediumStressSeconds)->toBe(1980)
        ->and($dtos[0]->highStressSeconds)->toBe(240)
        ->and($dtos[0]->bodyBatterySleep)->toBe(49)
        ->and($dtos[0]->avgSpo2)->toBe(93.0)
        ->and($dtos[0]->partialUpdate)->toBeFalse()
        ->and($dtos[0]->fillOnlyWhenNullFields)->toBe(['seven_day_avg_rhr']);
});

it('maps daily heart rate, body battery, and hydration into partial DailyHealthMetricData DTOs', function (): void {
    $mapper = new GarminPersonalMapper;

    /** @var array<string, mixed> $hrRaw */
    $hrRaw = loadGarminFixture('daily_heart_rate.json');
    $hr = $mapper->mapDailyHeartRate($hrRaw, '2026-11-21');

    expect($hr)->toBeInstanceOf(DailyHealthMetricData::class)
        ->and($hr?->provider)->toBe('garmin_personal')
        ->and($hr?->calendarDate)->toBe('2026-11-21')
        ->and($hr?->heartRateSeries)->toBe([[1795172400000, 48], [1795172640000, 56]])
        ->and($hr?->partialUpdate)->toBeTrue();

    // Intraday downsampling caps series > 288 points while keeping last point
    $largeSeries = [];
    for ($i = 0; $i < 600; $i++) {
        $largeSeries[] = [1795172400000 + ($i * 60000), 60 + ($i % 20)];
    }
    $downsampled = $mapper->mapDailyHeartRate([
        'calendarDate' => '2026-11-21',
        'heartRateValues' => $largeSeries,
    ], '2026-11-21');

    expect($downsampled)->not->toBeNull()
        ->and($downsampled?->heartRateSeries)->not->toBeNull()
        ->and(count($downsampled->heartRateSeries ?? []))->toBeLessThanOrEqual(289)
        ->and($downsampled?->heartRateSeries[count($downsampled->heartRateSeries) - 1])->toBe($largeSeries[599]);

    $bbEntries = loadGarminFixture('daily_body_battery.json');
    $bb = $mapper->mapDailyBodyBattery($bbEntries);

    expect($bb)->toHaveCount(1)
        ->and($bb[0]->provider)->toBe('garmin_personal')
        ->and($bb[0]->calendarDate)->toBe('2026-11-21')
        ->and($bb[0]->bodyBatteryCharged)->toBe(64)
        ->and($bb[0]->bodyBatteryDrained)->toBe(52)
        ->and($bb[0]->bodyBatterySeries)->toHaveCount(3)
        ->and($bb[0]->partialUpdate)->toBeTrue()
        ->and($bb[0]->fillOnlyWhenNullFields)->toBe(['body_battery_charged', 'body_battery_drained']);

    $hydrationEntries = loadGarminFixture('daily_hydration.json');
    $hydration = $mapper->mapDailyHydration($hydrationEntries);

    expect($hydration)->toHaveCount(1)
        ->and($hydration[0]->provider)->toBe('garmin_personal')
        ->and($hydration[0]->calendarDate)->toBe('2026-11-21')
        ->and($hydration[0]->hydrationMl)->toBe(2250)
        ->and($hydration[0]->partialUpdate)->toBeTrue();
});

it('maps sleep, HRV, VO2 max, fitness age, weight measurements, weight goals, and HR zones', function (): void {
    $mapper = new GarminPersonalMapper;

    $sleepFixtures = loadGarminFixture('sleep.json');
    /** @var array<string, mixed> $sleepSummaryRaw */
    $sleepSummaryRaw = $sleepFixtures['summary'];
    $sleepSummary = $mapper->mapSleep($sleepSummaryRaw, null);

    expect($sleepSummary)->toBeInstanceOf(SleepData::class)
        ->and($sleepSummary?->provider)->toBe('garmin_personal')
        ->and($sleepSummary?->calendarDate)->toBe('2026-11-21')
        ->and($sleepSummary?->sleepSeconds)->toBe(28800)
        ->and($sleepSummary?->deepSeconds)->toBe(5400)
        ->and($sleepSummary?->lightSeconds)->toBe(17100)
        ->and($sleepSummary?->remSeconds)->toBe(6300)
        ->and($sleepSummary?->awakeSeconds)->toBe(900)
        ->and($sleepSummary?->napSeconds)->toBe(0)
        ->and($sleepSummary?->sleepScore)->toBe(86)
        ->and($sleepSummary?->sleepStartLocal)->toBeInstanceOf(CarbonImmutable::class)
        ->and($sleepSummary?->sleepEndLocal)->toBeInstanceOf(CarbonImmutable::class);

    /** @var array<string, mixed> $sleepNeedsRaw */
    $sleepNeedsRaw = $sleepFixtures['dailySleepNeedsOnly'];
    $sleepNeeds = $mapper->mapSleep($sleepNeedsRaw, '2026-11-21');

    expect($sleepNeeds)->toBeInstanceOf(SleepData::class)
        ->and($sleepNeeds?->sleepSeconds)->toBeNull()
        ->and($sleepNeeds?->sleepNeedMinutes)->toBe(480)
        ->and($sleepNeeds?->nextSleepNeedMinutes)->toBe(470);

    expect($mapper->mapSleep(['calendarDate' => '2026-11-21'], '2026-11-21'))->toBeNull();

    $hrv = $mapper->mapHrvSummaries(loadGarminFixture('hrv_summaries.json'));
    expect($hrv)->toHaveCount(1)
        ->and($hrv[0])->toBeInstanceOf(HrvData::class)
        ->and($hrv[0]->provider)->toBe('garmin_personal')
        ->and($hrv[0]->calendarDate)->toBe('2026-11-21')
        ->and($hrv[0]->weeklyAvg)->toBe(54)
        ->and($hrv[0]->lastNightAvg)->toBe(58)
        ->and($hrv[0]->lastNight5minHigh)->toBe(81)
        ->and($hrv[0]->status)->toBe('BALANCED')
        ->and($hrv[0]->baselineLowUpper)->toBe(39)
        ->and($hrv[0]->baselineBalancedLow)->toBe(43)
        ->and($hrv[0]->baselineBalancedUpper)->toBe(59)
        ->and($hrv[0]->feedbackPhrase)->toBe('HRV_BALANCED_2');

    $vo2 = $mapper->mapVo2Max(loadGarminFixture('vo2_max.json'));
    expect($vo2)->toHaveCount(2)
        ->and($vo2[0])->toBeInstanceOf(Vo2MaxData::class)
        ->and($vo2[0]->provider)->toBe('garmin_personal')
        ->and($vo2[0]->sport)->toBe('running')
        ->and($vo2[0]->vo2MaxPrecise)->toBe(47.4)
        ->and($vo2[0]->vo2Max)->toBe(47.0)
        ->and($vo2[0]->subSport)->toBe('GENERIC')
        ->and($vo2[1]->sport)->toBe('cycling')
        ->and($vo2[1]->vo2MaxPrecise)->toBe(51.2);

    /** @var array<string, mixed> $fitnessAgeRaw */
    $fitnessAgeRaw = loadGarminFixture('fitness_age.json');
    $fitnessAge = $mapper->mapFitnessAge($fitnessAgeRaw, '2026-11-21');
    expect($fitnessAge)->toBeInstanceOf(FitnessAgeData::class)
        ->and($fitnessAge?->provider)->toBe('garmin_personal')
        ->and($fitnessAge?->calendarDate)->toBe('2026-11-21')
        ->and($fitnessAge?->chronologicalAge)->toBe(43.0)
        ->and($fitnessAge?->fitnessAge)->toBe(35.4)
        ->and($fitnessAge?->previousFitnessAge)->toBe(35.8)
        ->and($fitnessAge?->components)->toBe(['rhr' => ['value' => 47]])
        ->and($mapper->mapFitnessAge([], '2026-11-21'))->toBeNull();

    /** @var array<string, mixed> $weightRaw */
    $weightRaw = loadGarminFixture('weight_measurements.json');
    $weights = $mapper->mapWeightMeasurements($weightRaw, 'Pacific/Auckland');
    expect($weights)->toHaveCount(2)
        ->and($weights[0])->toBeInstanceOf(WeightMeasurementData::class)
        ->and($weights[0]->provider)->toBe('garmin_personal')
        ->and($weights[0]->externalId)->toBe('1795212000123')
        ->and($weights[0]->calendarDate)->toBe('2026-11-21')
        ->and($weights[0]->weightGrams)->toBe(82400)
        ->and($weights[0]->bmi)->toBe(24.6)
        ->and($weights[0]->bodyFatPct)->toBe(17.5)
        ->and($weights[0]->bodyWaterPct)->toBe(56.2)
        ->and($weights[0]->boneMassGrams)->toBe(3520)
        ->and($weights[0]->muscleMassGrams)->toBe(64800)
        ->and($weights[0]->sourceType)->toBe('INDEX_SCALE')
        ->and($weights[1]->externalId)->toBe('1795125600456')
        ->and($weights[1]->calendarDate)->toBe('2026-11-20')
        ->and($weights[1]->weightGrams)->toBe(82700);

    $weightGoals = $mapper->mapWeightGoals(loadGarminFixture('weight_goals.json'));
    expect($weightGoals)->toHaveCount(1)
        ->and($weightGoals[0])->toBeInstanceOf(WeightGoalData::class)
        ->and($weightGoals[0]->provider)->toBe('garmin_personal')
        ->and($weightGoals[0]->startDate)->toBe('2026-09-04')
        ->and($weightGoals[0]->endDate)->toBeNull()
        ->and($weightGoals[0]->goalGrams)->toBe(85000)
        ->and($weightGoals[0]->sourceCategory)->toBe('MANUAL')
        ->and($weightGoals[0]->updatedAt?->format('Y-m-d H:i:s'))->toBe('2026-09-04 08:40:40');

    $zones = $mapper->mapHeartRateZones(loadGarminFixture('heart_rate_zones.json'));
    expect($zones)->toHaveCount(1)
        ->and($zones[0])->toBeInstanceOf(HeartRateZoneData::class)
        ->and($zones[0]->provider)->toBe('garmin_personal')
        ->and($zones[0]->sport)->toBe('RUNNING')
        ->and($zones[0]->trainingMethod)->toBe('HR_MAX')
        ->and($zones[0]->maxHr)->toBe(197)
        ->and($zones[0]->restingHr)->toBe(47)
        ->and($zones[0]->lactateThresholdHr)->toBe(177)
        ->and($zones[0]->zone1Floor)->toBe(130)
        ->and($zones[0]->zone5Floor)->toBe(183);
});

it('extracts extended summary columns from stored Garmin raw_summary JSON', function (): void {
    $mapper = new GarminPersonalMapper;
    $activities = loadGarminFixture('activities.json');
    /** @var array<string, mixed> $runRaw */
    $runRaw = $activities[0];

    $extended = $mapper->extractExtendedSummaryFromRaw($runRaw);

    expect($extended['avg_grade_adjusted_speed'])->toBe(3.76)
        ->and($extended['bmr_calories'])->toBe(85)
        ->and($extended['sweat_loss_ml'])->toBe(620)
        ->and($extended['avg_vertical_oscillation'])->toBe(8.4)
        ->and($extended['avg_vertical_ratio'])->toBe(6.7)
        ->and($extended['avg_ground_contact_time'])->toBe(238.5)
        ->and($extended['aerobic_te_message'])->toBe('IMPROVING_AEROBIC_FITNESS_2')
        ->and($extended['anaerobic_te_message'])->toBe('MINOR_ANAEROBIC_BENEFIT_15')
        ->and($extended['avg_respiration_rate'])->toBe(36.2)
        ->and($extended['min_respiration_rate'])->toBe(24.0)
        ->and($extended['max_respiration_rate'])->toBe(45.5)
        ->and($extended['min_temperature'])->toBe(14.0)
        ->and($extended['max_temperature'])->toBe(19.5)
        ->and($extended['moderate_intensity_minutes'])->toBe(8)
        ->and($extended['vigorous_intensity_minutes'])->toBe(37)
        ->and($extended['run_duration_seconds'])->toBe(2640.46)
        ->and($extended['walk_duration_seconds'])->toBe(45.12)
        ->and($extended['idle_duration_seconds'])->toBe(24.42)
        ->and($mapper->extractExtendedSummaryFromRaw(null))->toBe([])
        ->and($mapper->extractExtendedSummaryFromRaw([]))->toBe([]);
});

it('exposes GarminPersonalProvider metadata, capabilities, PullProvider methods, optional 404 handling, and archive download', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = mapperFakeJwt();
    $activities = loadGarminFixture('activities.json');
    $dailyV1 = loadGarminFixture('daily_summary_v1.json');
    $dailyV2 = loadGarminFixture('daily_summaries_v2.json');
    $dailyHr = loadGarminFixture('daily_heart_rate.json');
    $dailyBb = loadGarminFixture('daily_body_battery.json');
    $dailyHydration = loadGarminFixture('daily_hydration.json');
    $sleepFixtures = loadGarminFixture('sleep.json');
    $hrv = loadGarminFixture('hrv_summaries.json');
    $vo2 = loadGarminFixture('vo2_max.json');
    $fitnessAge = loadGarminFixture('fitness_age.json');
    $weights = loadGarminFixture('weight_measurements.json');
    $weightGoals = loadGarminFixture('weight_goals.json');
    $hrZones = loadGarminFixture('heart_rate_zones.json');

    $provider = makeGarminProviderWithResponder(static function (HttpRequest $request) use (
        $jwt,
        $activities,
        $dailyV1,
        $dailyV2,
        $dailyHr,
        $dailyBb,
        $dailyHydration,
        $sleepFixtures,
        $hrv,
        $vo2,
        $fitnessAge,
        $weights,
        $weightGoals,
        $hrZones,
    ): HttpResponse {
        $url = $request->url;

        if (str_contains($url, '/personalapitoken-service/')) {
            return new HttpResponse(200, $jwt);
        }

        if (str_contains($url, '/activitylist-service/activities/search/activities')) {
            return new HttpResponse(200, json_encode($activities, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/activity-service/activity/9001/workouts')) {
            return new HttpResponse(200, json_encode([['workoutId' => 42]], JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/activity-service/activity/9001')) {
            return new HttpResponse(200, json_encode($activities[0], JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/activity-service/activity/activityTypes/simple')) {
            return new HttpResponse(200, json_encode([['typeKey' => 'running']], JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/usersummary-service/usersummary/daily')) {
            return str_contains($url, '2026-11-20')
                ? new HttpResponse(404, 'Not found')
                : new HttpResponse(200, json_encode($dailyV1, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/usersummary-service/v2/dailysummaries')) {
            return new HttpResponse(200, json_encode($dailyV2, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/wellness-service/wellness/dailyHeartRate')) {
            return new HttpResponse(200, json_encode($dailyHr, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/wellness-service/wellness/bodyBattery/reports/daily')) {
            return new HttpResponse(200, json_encode($dailyBb, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/usersummary-service/stats/hydration/daily/')) {
            return new HttpResponse(200, json_encode($dailyHydration, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/sleep-service/sleep/dailySleepSummariesByDate')) {
            return new HttpResponse(200, json_encode([$sleepFixtures['summary']], JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/sleep-service/sleep/dailySleep')) {
            return new HttpResponse(200, json_encode($sleepFixtures['dailySleepNeedsOnly'], JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/hrv-service/hrv/daily/')) {
            return new HttpResponse(200, json_encode($hrv, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/metrics-service/metrics/maxmet/daily/')) {
            return new HttpResponse(200, json_encode($vo2, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/fitnessage-service/fitnessage/v2/2026-11-21')) {
            return new HttpResponse(200, json_encode($fitnessAge, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/fitnessage-service/fitnessage/v2/')) {
            return new HttpResponse(404, 'Missing');
        }

        if (str_contains($url, '/weight-service/weight/range/')) {
            return new HttpResponse(200, json_encode($weights, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/goal-service/goal/user/effective/weightgoal/')) {
            return new HttpResponse(200, json_encode($weightGoals, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/biometric-service/heartRateZones/v2')) {
            return new HttpResponse(200, json_encode($hrZones, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/download-service/files/activity/9001')) {
            return new HttpResponse(200, "PK\x03\x04fitzipbytes");
        }

        return new HttpResponse(500, 'Unexpected server error');
    });

    $context = new ProviderContext(
        scopeKey: 'default',
        timezone: 'Pacific/Auckland',
        credentials: ['api_key' => 'test-key'],
    );

    expect($provider->id())->toBe('garmin_personal')
        ->and($provider->displayName())->toBe('Garmin Connect (Personal API)')
        ->and($provider->capabilities()->toArray())->toBe([
            'supports_activities' => true,
            'supports_activity_archives' => true,
            'supports_daily_metrics' => true,
            'supports_sleep' => true,
            'supports_hrv' => true,
            'supports_vo2_max' => true,
            'supports_fitness_age' => true,
            'supports_weight' => true,
            'supports_heart_rate_zones' => true,
            'supports_webhooks' => false,
            'allows_group_sharing' => true,
            'requires_commercial_license' => false,
        ]);

    expect($provider->fetchActivities($context, limit: 10))->toHaveCount(4)
        ->and($provider->fetchActivities($context, sinceUtc: CarbonImmutable::parse('2026-11-20 00:00:00', 'UTC')))->toHaveCount(2)
        ->and($provider->fetchActivityDetail($context, '9001')?->externalId)->toBe('9001')
        ->and($provider->fetchActivityWorkouts($context, '9001'))->toBe([['workoutId' => 42]])
        ->and($provider->fetchActivityTypes($context))->toBe([['typeKey' => 'running']])
        ->and($provider->fetchDailySummary($context, '2026-11-21')?->steps)->toBe(9420)
        ->and($provider->fetchDailySummary($context, '2026-11-20'))->toBeNull() // 404 swallowed by callOptional
        ->and($provider->fetchDailyMetrics($context, '2026-11-21', '2026-11-21', includeIntraday: true))->toHaveCount(4)
        ->and($provider->fetchSleep($context, '2026-11-21', '2026-11-21'))->toHaveCount(1)
        ->and($provider->fetchSleepForDate($context, '2026-11-21')?->sleepNeedMinutes)->toBe(480)
        ->and($provider->fetchHrv($context, '2026-11-21', '2026-11-21'))->toHaveCount(1)
        ->and($provider->fetchVo2Max($context, '2026-11-21', '2026-11-21'))->toHaveCount(2)
        ->and($provider->fetchFitnessAge($context, '2026-11-20', '2026-11-21', stepDays: 1))->toHaveCount(1)
        ->and($provider->fetchWeightMeasurements($context, '2026-11-20', '2026-11-21'))->toHaveCount(2)
        ->and($provider->fetchWeightGoals($context, '2026-09-01', '2026-11-21'))->toHaveCount(1)
        ->and($provider->fetchHeartRateZones($context))->toHaveCount(1)
        ->and($provider->downloadActivityArchive($context, '9001'))->toBe([
            'bytes' => "PK\x03\x04fitzipbytes",
            'format' => 'fit_zip',
        ]);

    // Non-404/204 errors (e.g. 500) are rethrown rather than swallowed
    expect(fn (): ?NormalizedActivity => $provider->fetchActivityDetail($context, '999999'))
        ->toThrow(ProviderException::class, 'HTTP 500');
});
