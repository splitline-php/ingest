<?php

declare(strict_types=1);

namespace Splitline\ProviderGarminPersonal;

use Carbon\CarbonImmutable;
use Splitline\Core\Analytics\ActivityClassifier;
use Splitline\Core\Data\DailyHealthMetricData;
use Splitline\Core\Data\FitnessAgeData;
use Splitline\Core\Data\HeartRateZoneData;
use Splitline\Core\Data\HrvData;
use Splitline\Core\Data\NormalizedActivity;
use Splitline\Core\Data\SleepData;
use Splitline\Core\Data\Vo2MaxData;
use Splitline\Core\Data\WeightGoalData;
use Splitline\Core\Data\WeightMeasurementData;
use Splitline\Core\Support\PayloadCoercer;

final class GarminPersonalMapper
{
    public const string PROVIDER_ID = 'garmin_personal';

    private const int MAX_INTRADAY_SERIES_POINTS = 288;

    /**
     * @var list<int>
     */
    private const array POWER_CURVE_DURATIONS = [
        1, 2, 5, 10, 20, 30, 60, 120, 300, 600, 1200, 1800, 3600, 7200,
    ];

    /**
     * @var array<string, array{distance_key: string, distance_meters: float}>
     */
    private const array RUNNING_SPLIT_MAP = [
        'fastestSplit_1000' => ['distance_key' => '1k', 'distance_meters' => 1000.0],
        'fastestSplit_1609' => ['distance_key' => '1mi', 'distance_meters' => 1609.34],
        'fastestSplit_5000' => ['distance_key' => '5k', 'distance_meters' => 5000.0],
        'fastestSplit_10000' => ['distance_key' => '10k', 'distance_meters' => 10000.0],
    ];

    /**
     * @var array<string, array{distance_key: string, distance_meters: float}>
     */
    private const array BIKING_SPLIT_MAP = [
        'fastestSplit_40000' => ['distance_key' => '40k', 'distance_meters' => 40000.0],
    ];

    public function __construct(
        private readonly ActivityClassifier $classifier = new ActivityClassifier,
    ) {}

    /**
     * Map a Garmin Connect activity summary or detail payload into a canonical NormalizedActivity DTO.
     *
     * @param  array<string, mixed>  $raw
     */
    public function mapActivity(array $raw, string $timezone): ?NormalizedActivity
    {
        $summaryDto = PayloadCoercer::asAssocArray($raw['summaryDTO'] ?? null);
        $source = $summaryDto !== null ? [...$summaryDto, ...$raw] : $raw;

        $garminActivityId = PayloadCoercer::asInt($source['activityId'] ?? null);

        if ($garminActivityId === null || $garminActivityId <= 0) {
            return null;
        }

        $activityType = PayloadCoercer::asAssocArray($source['activityType'] ?? $source['activityTypeDTO'] ?? null);
        $typeKey = PayloadCoercer::asString($activityType['typeKey'] ?? null) ?? 'other';
        $classification = $this->classifier->classify($typeKey);

        [$startedAtLocal, $startedAtUtc] = $this->resolveActivityStartTimes($source, $timezone);

        if ($startedAtLocal === null || $startedAtUtc === null) {
            return null;
        }

        $isBiking = $classification['sport_category'] === 'biking';

        $avgCadence = $isBiking
            ? (PayloadCoercer::asFloat($source['averageBikingCadenceInRevPerMinute'] ?? null)
                ?? PayloadCoercer::asFloat($source['averageRunningCadenceInStepsPerMinute'] ?? null))
            : (PayloadCoercer::asFloat($source['averageRunningCadenceInStepsPerMinute'] ?? null)
                ?? PayloadCoercer::asFloat($source['averageBikingCadenceInRevPerMinute'] ?? null));

        $maxCadence = $isBiking
            ? (PayloadCoercer::asFloat($source['maxBikingCadenceInRevPerMinute'] ?? null)
                ?? PayloadCoercer::asFloat($source['maxRunningCadenceInStepsPerMinute'] ?? null))
            : (PayloadCoercer::asFloat($source['maxRunningCadenceInStepsPerMinute'] ?? null)
                ?? PayloadCoercer::asFloat($source['maxBikingCadenceInRevPerMinute'] ?? null));

        $runDurationSeconds = null;
        $walkDurationSeconds = null;
        $idleDurationSeconds = null;

        if (isset($source['splitSummaries']) && is_array($source['splitSummaries'])) {
            foreach ($source['splitSummaries'] as $split) {
                if (! is_array($split)) {
                    continue;
                }

                $splitType = PayloadCoercer::asString($split['splitType'] ?? null);
                $splitDuration = PayloadCoercer::asFloat($split['duration'] ?? null);

                if ($splitType === null || $splitDuration === null) {
                    continue;
                }

                if ($splitType === 'RWD_RUN') {
                    $runDurationSeconds = round($splitDuration, 2);
                } elseif ($splitType === 'RWD_WALK') {
                    $walkDurationSeconds = round($splitDuration, 2);
                } elseif ($splitType === 'RWD_STAND') {
                    $idleDurationSeconds = round($splitDuration, 2);
                }
            }
        }

        return new NormalizedActivity(
            provider: self::PROVIDER_ID,
            externalId: (string) $garminActivityId,
            name: PayloadCoercer::asString($source['activityName'] ?? null) ?? 'Untitled Activity',
            activityTypeKey: $typeKey,
            sportCategory: $classification['sport_category'],
            sportDiscipline: $classification['sport_discipline'],
            isIndoor: $classification['is_indoor'],
            startedAtLocal: $startedAtLocal,
            startedAtUtc: $startedAtUtc,
            distanceMeters: PayloadCoercer::asFloat($source['distance'] ?? null) ?? 0.0,
            durationSeconds: PayloadCoercer::asFloat($source['duration'] ?? null) ?? 0.0,
            locationName: PayloadCoercer::asString($source['locationName'] ?? null),
            startLat: PayloadCoercer::asFloat($source['startLatitude'] ?? null),
            startLng: PayloadCoercer::asFloat($source['startLongitude'] ?? null),
            movingDurationSeconds: PayloadCoercer::asFloat($source['movingDuration'] ?? null),
            elapsedDurationSeconds: PayloadCoercer::asFloat($source['elapsedDuration'] ?? null),
            elevationGain: PayloadCoercer::asFloat($source['elevationGain'] ?? null),
            elevationLoss: PayloadCoercer::asFloat($source['elevationLoss'] ?? null),
            minElevation: PayloadCoercer::asFloat($source['minElevation'] ?? null),
            maxElevation: PayloadCoercer::asFloat($source['maxElevation'] ?? null),
            avgSpeed: PayloadCoercer::asFloat($source['averageSpeed'] ?? null),
            maxSpeed: PayloadCoercer::asFloat($source['maxSpeed'] ?? null),
            avgGradeAdjustedSpeed: PayloadCoercer::asFloat($source['avgGradeAdjustedSpeed'] ?? null),
            calories: PayloadCoercer::asInt($source['calories'] ?? null),
            bmrCalories: PayloadCoercer::asInt($source['bmrCalories'] ?? null),
            sweatLossMl: PayloadCoercer::asInt($source['waterEstimated'] ?? null),
            avgHr: PayloadCoercer::asInt($source['averageHR'] ?? null),
            maxHr: PayloadCoercer::asInt($source['maxHR'] ?? null),
            avgCadence: $avgCadence,
            maxCadence: $maxCadence,
            avgStrideLength: PayloadCoercer::asFloat($source['avgStrideLength'] ?? null),
            avgVerticalOscillation: PayloadCoercer::asFloat($source['avgVerticalOscillation'] ?? null),
            avgVerticalRatio: PayloadCoercer::asFloat($source['avgVerticalRatio'] ?? null),
            avgGroundContactTime: PayloadCoercer::asFloat($source['avgGroundContactTime'] ?? null),
            steps: PayloadCoercer::asInt($source['steps'] ?? null),
            aerobicTe: PayloadCoercer::asFloat($source['aerobicTrainingEffect'] ?? null),
            anaerobicTe: PayloadCoercer::asFloat($source['anaerobicTrainingEffect'] ?? null),
            trainingLoad: PayloadCoercer::asFloat($source['activityTrainingLoad'] ?? null),
            trainingEffectLabel: PayloadCoercer::asString($source['trainingEffectLabel'] ?? null),
            aerobicTeMessage: PayloadCoercer::asString($source['aerobicTrainingEffectMessage'] ?? null),
            anaerobicTeMessage: PayloadCoercer::asString($source['anaerobicTrainingEffectMessage'] ?? null),
            bodyBatteryDiff: PayloadCoercer::asInt($source['differenceBodyBattery'] ?? null),
            hrZone1Seconds: PayloadCoercer::asFloat($source['hrTimeInZone_1'] ?? null),
            hrZone2Seconds: PayloadCoercer::asFloat($source['hrTimeInZone_2'] ?? null),
            hrZone3Seconds: PayloadCoercer::asFloat($source['hrTimeInZone_3'] ?? null),
            hrZone4Seconds: PayloadCoercer::asFloat($source['hrTimeInZone_4'] ?? null),
            hrZone5Seconds: PayloadCoercer::asFloat($source['hrTimeInZone_5'] ?? null),
            avgPower: PayloadCoercer::asFloat($source['avgPower'] ?? null),
            maxPower: PayloadCoercer::asFloat($source['maxPower'] ?? null),
            normalizedPower: PayloadCoercer::asFloat($source['normPower'] ?? $source['normalizedPower'] ?? null),
            ftp: PayloadCoercer::asFloat($source['functionalThresholdPower'] ?? null),
            tss: PayloadCoercer::asFloat($source['trainingStressScore'] ?? null),
            intensityFactor: PayloadCoercer::asFloat($source['intensityFactor'] ?? null),
            totalWork: PayloadCoercer::asFloat($source['totalWork'] ?? null),
            leftBalance: PayloadCoercer::asFloat($source['avgLeftBalance'] ?? $source['leftBalance'] ?? null),
            max20minPower: PayloadCoercer::asFloat($source['max20MinPower'] ?? null),
            powerZone1Seconds: PayloadCoercer::asFloat($source['powerTimeInZone_1'] ?? null),
            powerZone2Seconds: PayloadCoercer::asFloat($source['powerTimeInZone_2'] ?? null),
            powerZone3Seconds: PayloadCoercer::asFloat($source['powerTimeInZone_3'] ?? null),
            powerZone4Seconds: PayloadCoercer::asFloat($source['powerTimeInZone_4'] ?? null),
            powerZone5Seconds: PayloadCoercer::asFloat($source['powerTimeInZone_5'] ?? null),
            powerZone6Seconds: PayloadCoercer::asFloat($source['powerTimeInZone_6'] ?? null),
            powerZone7Seconds: PayloadCoercer::asFloat($source['powerTimeInZone_7'] ?? null),
            powerCurve: $this->extractPowerCurve($source),
            grit: PayloadCoercer::asFloat($source['grit'] ?? null),
            avgFlow: PayloadCoercer::asFloat($source['avgFlow'] ?? null),
            jumpCount: PayloadCoercer::asInt($source['jumpCount'] ?? null),
            unpavedPct: $this->extractUnpavedPercentage($source),
            workoutFeel: PayloadCoercer::asInt($source['workoutFeel'] ?? null),
            workoutRpe: PayloadCoercer::asInt($source['workoutRpe'] ?? null),
            beginStamina: PayloadCoercer::asInt($source['beginPotentialStamina'] ?? null),
            endStamina: PayloadCoercer::asInt($source['endPotentialStamina'] ?? null),
            minStamina: PayloadCoercer::asInt($source['minActivityStamina'] ?? null),
            avgRespirationRate: PayloadCoercer::asFloat($source['avgRespirationRate'] ?? null),
            minRespirationRate: PayloadCoercer::asFloat($source['minRespirationRate'] ?? null),
            maxRespirationRate: PayloadCoercer::asFloat($source['maxRespirationRate'] ?? null),
            minTemperature: PayloadCoercer::asFloat($source['minTemperature'] ?? null),
            maxTemperature: PayloadCoercer::asFloat($source['maxTemperature'] ?? null),
            moderateIntensityMinutes: PayloadCoercer::asInt($source['moderateIntensityMinutes'] ?? null),
            vigorousIntensityMinutes: PayloadCoercer::asInt($source['vigorousIntensityMinutes'] ?? null),
            runDurationSeconds: $runDurationSeconds,
            walkDurationSeconds: $walkDurationSeconds,
            idleDurationSeconds: $idleDurationSeconds,
            isFavorite: (bool) ($source['isFavorite'] ?? $source['favorite'] ?? false),
            isPr: (bool) ($source['isPR'] ?? $source['pr'] ?? false),
            isManual: (bool) ($source['isManualActivity'] ?? $source['manualActivity'] ?? false),
            seededSplits: $this->extractSeededSplits($source, $classification['sport_category'], $classification['is_indoor']),
            rawSummary: $raw,
        );
    }

    /**
     * Map a Garmin v1 daily summary payload into a DailyHealthMetricData DTO.
     *
     * @param  array<string, mixed>  $raw
     */
    public function mapDailySummary(array $raw, string $fallbackDate): ?DailyHealthMetricData
    {
        $calendarDate = PayloadCoercer::extractDateString($raw['calendarDate'] ?? null) ?? $fallbackDate;

        $dto = new DailyHealthMetricData(
            provider: self::PROVIDER_ID,
            calendarDate: $calendarDate,
            dayDurationSeconds: PayloadCoercer::positiveMsToSeconds($raw['durationInMilliseconds'] ?? null),
            steps: PayloadCoercer::asInt($raw['totalSteps'] ?? null),
            stepGoal: PayloadCoercer::asInt($raw['dailyStepGoal'] ?? null),
            distanceMeters: PayloadCoercer::asFloat($raw['totalDistanceMeters'] ?? null),
            totalCalories: PayloadCoercer::asInt($raw['totalKilocalories'] ?? null),
            activeCalories: PayloadCoercer::asInt($raw['activeKilocalories'] ?? null),
            bmrCalories: PayloadCoercer::asInt($raw['bmrKilocalories'] ?? null),
            restingHr: PayloadCoercer::asInt($raw['restingHeartRate'] ?? null),
            sevenDayAvgRhr: PayloadCoercer::asInt($raw['lastSevenDaysAvgRestingHeartRate'] ?? null),
            minHr: PayloadCoercer::asInt($raw['minHeartRate'] ?? null),
            maxHr: PayloadCoercer::asInt($raw['maxHeartRate'] ?? null),
            avgStress: PayloadCoercer::asNonNegativeInt($raw['averageStressLevel'] ?? null),
            maxStress: PayloadCoercer::asNonNegativeInt($raw['maxStressLevel'] ?? null),
            stressQualifier: PayloadCoercer::asString($raw['stressQualifier'] ?? null),
            restStressSeconds: PayloadCoercer::asInt($raw['restStressDuration'] ?? null),
            lowStressSeconds: PayloadCoercer::asInt($raw['lowStressDuration'] ?? null),
            mediumStressSeconds: PayloadCoercer::asInt($raw['mediumStressDuration'] ?? null),
            highStressSeconds: PayloadCoercer::asInt($raw['highStressDuration'] ?? null),
            bodyBatteryCurrent: PayloadCoercer::asInt($raw['bodyBatteryMostRecentValue'] ?? null),
            bodyBatteryHigh: PayloadCoercer::asInt($raw['bodyBatteryHighestValue'] ?? null),
            bodyBatteryLow: PayloadCoercer::asInt($raw['bodyBatteryLowestValue'] ?? null),
            bodyBatteryCharged: PayloadCoercer::asInt($raw['bodyBatteryChargedValue'] ?? null),
            bodyBatteryDrained: PayloadCoercer::asInt($raw['bodyBatteryDrainedValue'] ?? null),
            bodyBatterySleep: PayloadCoercer::asInt($raw['bodyBatteryDuringSleep'] ?? null),
            moderateIntensityMinutes: PayloadCoercer::asInt($raw['moderateIntensityMinutes'] ?? null),
            vigorousIntensityMinutes: PayloadCoercer::asInt($raw['vigorousIntensityMinutes'] ?? null),
            intensityMinutesGoal: PayloadCoercer::asInt($raw['intensityMinutesGoal'] ?? null),
            floorsAscended: PayloadCoercer::asFloat($raw['floorsAscended'] ?? null),
            floorsDescended: PayloadCoercer::asFloat($raw['floorsDescended'] ?? null),
            floorsGoal: PayloadCoercer::asInt($raw['userFloorsAscendedGoal'] ?? null),
            avgRespiration: PayloadCoercer::asFloat($raw['avgWakingRespirationValue'] ?? null),
            minRespiration: PayloadCoercer::asFloat($raw['lowestRespirationValue'] ?? null),
            maxRespiration: PayloadCoercer::asFloat($raw['highestRespirationValue'] ?? null),
            avgSpo2: PayloadCoercer::asFloat($raw['averageSpo2'] ?? null),
            lowestSpo2: PayloadCoercer::asFloat($raw['lowestSpo2'] ?? null),
            latestSpo2: PayloadCoercer::asFloat($raw['latestSpo2'] ?? null),
            rawPayload: $raw,
            partialUpdate: false,
        );

        return $this->hasDailyMetricValues($dto) ? $dto : null;
    }

    /**
     * Map a Garmin v2 multi-day daily summaries response into DailyHealthMetricData DTOs.
     *
     * @param  array<string, mixed>  $response
     * @return list<DailyHealthMetricData>
     */
    public function mapDailySummariesV2(array $response): array
    {
        $entries = PayloadCoercer::asArray($response['data'] ?? null);

        if ($entries === null) {
            return [];
        }

        $results = [];

        foreach ($entries as $item) {
            $entry = PayloadCoercer::asAssocArray($item);

            if ($entry === null) {
                continue;
            }

            $calendarDate = PayloadCoercer::extractDateString($entry['calendarDate'] ?? null);

            if ($calendarDate === null) {
                continue;
            }

            $chronology = PayloadCoercer::asAssocArray($entry['wellnessChronology'] ?? null);
            $movement = PayloadCoercer::asAssocArray($entry['movement'] ?? null);
            $steps = PayloadCoercer::asAssocArray($movement['steps'] ?? null);
            $floorsAscended = PayloadCoercer::asAssocArray($movement['floorsAscended'] ?? null);
            $floorsDescended = PayloadCoercer::asAssocArray($movement['floorsDescended'] ?? null);
            $calories = PayloadCoercer::asAssocArray($entry['calories'] ?? null);
            $heartRate = PayloadCoercer::asAssocArray($entry['heartRate'] ?? null);
            $intensity = PayloadCoercer::asAssocArray($entry['intensityMinutes'] ?? null);
            $stress = PayloadCoercer::asAssocArray($entry['stress'] ?? null);
            $bodyBattery = PayloadCoercer::asAssocArray($entry['bodyBattery'] ?? null);
            $respiration = PayloadCoercer::asAssocArray($entry['respiration'] ?? null);
            $pulseOx = PayloadCoercer::asAssocArray($entry['pulseOx'] ?? null);

            $dto = new DailyHealthMetricData(
                provider: self::PROVIDER_ID,
                calendarDate: $calendarDate,
                dayDurationSeconds: PayloadCoercer::positiveMsToSeconds($chronology['totalDurationInMillis'] ?? null),
                steps: PayloadCoercer::asInt($steps['value'] ?? null),
                stepGoal: PayloadCoercer::asInt($steps['goal'] ?? null),
                distanceMeters: PayloadCoercer::asFloat($steps['distanceInMeters'] ?? null),
                totalCalories: PayloadCoercer::asInt($calories['burnedTotal'] ?? null),
                activeCalories: PayloadCoercer::asInt($calories['burnedActive'] ?? null),
                bmrCalories: PayloadCoercer::asInt($calories['burnedResting'] ?? null),
                restingHr: PayloadCoercer::asInt($heartRate['restingValue'] ?? null),
                minHr: PayloadCoercer::asInt($heartRate['minValue'] ?? null),
                maxHr: PayloadCoercer::asInt($heartRate['maxValue'] ?? null),
                avgStress: PayloadCoercer::asNonNegativeInt($stress['avgLevel'] ?? null),
                maxStress: PayloadCoercer::asNonNegativeInt($stress['maxLevel'] ?? null),
                stressQualifier: PayloadCoercer::asString($stress['qualifier'] ?? null),
                restStressSeconds: PayloadCoercer::msToSeconds($stress['restDurationInMillis'] ?? null),
                lowStressSeconds: PayloadCoercer::msToSeconds($stress['lowStressDurationInMillis'] ?? null),
                mediumStressSeconds: PayloadCoercer::msToSeconds($stress['mediumStressDurationInMillis'] ?? null),
                highStressSeconds: PayloadCoercer::msToSeconds($stress['highStressDurationInMillis'] ?? null),
                bodyBatteryCurrent: PayloadCoercer::asInt($bodyBattery['latestValue'] ?? null),
                bodyBatteryHigh: PayloadCoercer::asInt($bodyBattery['maxValue'] ?? null),
                bodyBatteryLow: PayloadCoercer::asInt($bodyBattery['minValue'] ?? null),
                bodyBatteryCharged: PayloadCoercer::asInt($bodyBattery['chargedValue'] ?? null),
                bodyBatteryDrained: PayloadCoercer::asInt($bodyBattery['drainedValue'] ?? null),
                bodyBatterySleep: $this->extractBodyBatterySleepImpact($bodyBattery),
                moderateIntensityMinutes: PayloadCoercer::asInt($intensity['moderate'] ?? null),
                vigorousIntensityMinutes: PayloadCoercer::asInt($intensity['vigorous'] ?? null),
                intensityMinutesGoal: PayloadCoercer::asInt($intensity['goal'] ?? null),
                floorsAscended: PayloadCoercer::asFloat($floorsAscended['value'] ?? null),
                floorsDescended: PayloadCoercer::asFloat($floorsDescended['value'] ?? null),
                floorsGoal: PayloadCoercer::asInt($floorsAscended['goal'] ?? null),
                avgRespiration: PayloadCoercer::asFloat($respiration['avgValue'] ?? null),
                minRespiration: PayloadCoercer::asFloat($respiration['minValue'] ?? null),
                maxRespiration: PayloadCoercer::asFloat($respiration['maxValue'] ?? null),
                avgSpo2: PayloadCoercer::asFloat($pulseOx['avgValue'] ?? null),
                lowestSpo2: PayloadCoercer::asFloat($pulseOx['minValue'] ?? null),
                latestSpo2: PayloadCoercer::asFloat($pulseOx['latestValue'] ?? null),
                rawPayload: $entry,
                partialUpdate: false,
                fillOnlyWhenNullFields: ['seven_day_avg_rhr'],
            );

            if (! $this->hasDailyMetricValues($dto)) {
                continue;
            }

            $results[] = $dto;
        }

        return $results;
    }

    /**
     * Map an intraday daily heart rate payload into a partial DailyHealthMetricData DTO.
     *
     * @param  array<string, mixed>  $raw
     */
    public function mapDailyHeartRate(array $raw, string $fallbackDate): ?DailyHealthMetricData
    {
        $rawValues = PayloadCoercer::asArray($raw['heartRateValues'] ?? null);

        if ($rawValues === null) {
            return null;
        }

        $series = $this->normalizeIntradaySeries($rawValues, minValue: 1);

        if ($series === []) {
            return null;
        }

        $calendarDate = PayloadCoercer::extractDateString($raw['calendarDate'] ?? null) ?? $fallbackDate;

        return new DailyHealthMetricData(
            provider: self::PROVIDER_ID,
            calendarDate: $calendarDate,
            heartRateSeries: $series,
            partialUpdate: true,
        );
    }

    /**
     * Map daily Body Battery report entries into partial DailyHealthMetricData DTOs.
     *
     * @param  array<int|string, mixed>  $entries
     * @return list<DailyHealthMetricData>
     */
    public function mapDailyBodyBattery(array $entries): array
    {
        $results = [];

        foreach ($entries as $item) {
            $entry = PayloadCoercer::asAssocArray($item);

            if ($entry === null) {
                continue;
            }

            $calendarDate = PayloadCoercer::extractDateString($entry['date'] ?? $entry['calendarDate'] ?? null);

            if ($calendarDate === null) {
                continue;
            }

            $rawValues = PayloadCoercer::asArray($entry['bodyBatteryValuesArray'] ?? null);
            $series = $rawValues !== null ? $this->normalizeIntradaySeries($rawValues, minValue: 0) : [];
            $charged = PayloadCoercer::asInt($entry['charged'] ?? null);
            $drained = PayloadCoercer::asInt($entry['drained'] ?? null);

            if ($series === [] && $charged === null && $drained === null) {
                continue;
            }

            $results[] = new DailyHealthMetricData(
                provider: self::PROVIDER_ID,
                calendarDate: $calendarDate,
                bodyBatteryCharged: $charged,
                bodyBatteryDrained: $drained,
                bodyBatterySeries: $series !== [] ? $series : null,
                partialUpdate: true,
                fillOnlyWhenNullFields: ['body_battery_charged', 'body_battery_drained'],
            );
        }

        return $results;
    }

    /**
     * Map daily hydration stat entries into partial DailyHealthMetricData DTOs.
     *
     * @param  array<int|string, mixed>  $entries
     * @return list<DailyHealthMetricData>
     */
    public function mapDailyHydration(array $entries): array
    {
        $results = [];

        foreach ($entries as $item) {
            $entry = PayloadCoercer::asAssocArray($item);

            if ($entry === null) {
                continue;
            }

            $calendarDate = PayloadCoercer::extractDateString($entry['calendarDate'] ?? null);
            $hydrationMl = PayloadCoercer::asInt($entry['valueInML'] ?? null);

            if ($calendarDate === null || $hydrationMl === null || $hydrationMl <= 0) {
                continue;
            }

            $results[] = new DailyHealthMetricData(
                provider: self::PROVIDER_ID,
                calendarDate: $calendarDate,
                hydrationMl: $hydrationMl,
                partialUpdate: true,
            );
        }

        return $results;
    }

    /**
     * Map a Garmin dailySleep or dailySleepSummaries entry into a SleepData DTO.
     *
     * @param  array<string, mixed>  $raw
     */
    public function mapSleep(array $raw, ?string $fallbackDate = null): ?SleepData
    {
        $dto = PayloadCoercer::asAssocArray($raw['dailySleepDTO'] ?? null) ?? $raw;

        $calendarDate = PayloadCoercer::extractDateString($dto['calendarDate'] ?? $raw['calendarDate'] ?? null)
            ?? $fallbackDate;

        if ($calendarDate === null) {
            return null;
        }

        $sleepScores = PayloadCoercer::asAssocArray($dto['sleepScores'] ?? $raw['sleepScores'] ?? null);
        $overallScore = PayloadCoercer::asAssocArray($sleepScores['overall'] ?? null);
        $sleepNeed = PayloadCoercer::asAssocArray($dto['sleepNeed'] ?? $raw['sleepNeed'] ?? null);
        $nextSleepNeed = PayloadCoercer::asAssocArray($dto['nextSleepNeed'] ?? $raw['nextSleepNeed'] ?? null);

        $sleepSeconds = PayloadCoercer::asInt($dto['sleepTimeSeconds'] ?? null);
        $deepSeconds = PayloadCoercer::asInt($dto['deepSleepSeconds'] ?? null);
        $lightSeconds = PayloadCoercer::asInt($dto['lightSleepSeconds'] ?? null);
        $remSeconds = PayloadCoercer::asInt($dto['remSleepSeconds'] ?? null);
        $awakeSeconds = PayloadCoercer::asInt($dto['awakeSleepSeconds'] ?? null);
        $napSeconds = PayloadCoercer::asInt($dto['napTimeSeconds'] ?? null);
        $sleepStartLocal = PayloadCoercer::timestampMsToLocal($dto['sleepStartTimestampLocal'] ?? null);
        $sleepEndLocal = PayloadCoercer::timestampMsToLocal($dto['sleepEndTimestampLocal'] ?? null);
        $sleepScore = PayloadCoercer::asInt($overallScore['value'] ?? null);
        $sleepNeedMinutes = PayloadCoercer::asInt($sleepNeed['actual'] ?? null);
        $nextSleepNeedMinutes = PayloadCoercer::asInt($nextSleepNeed['actual'] ?? null);

        if (
            $sleepSeconds === null
            && $deepSeconds === null
            && $lightSeconds === null
            && $remSeconds === null
            && $awakeSeconds === null
            && $napSeconds === null
            && $sleepStartLocal === null
            && $sleepEndLocal === null
            && $sleepScore === null
            && $sleepNeedMinutes === null
            && $nextSleepNeedMinutes === null
        ) {
            return null;
        }

        return new SleepData(
            provider: self::PROVIDER_ID,
            calendarDate: $calendarDate,
            sleepSeconds: $sleepSeconds,
            deepSeconds: $deepSeconds,
            lightSeconds: $lightSeconds,
            remSeconds: $remSeconds,
            awakeSeconds: $awakeSeconds,
            napSeconds: $napSeconds,
            sleepStartLocal: $sleepStartLocal,
            sleepEndLocal: $sleepEndLocal,
            sleepScore: $sleepScore,
            sleepNeedMinutes: $sleepNeedMinutes,
            nextSleepNeedMinutes: $nextSleepNeedMinutes,
            rawPayload: $raw,
        );
    }

    /**
     * Map Garmin HRV summary payloads into HrvData DTOs.
     *
     * @param  array<int|string, mixed>  $response
     * @return list<HrvData>
     */
    public function mapHrvSummaries(array $response): array
    {
        $items = isset($response['hrvSummaries']) && is_array($response['hrvSummaries'])
            ? $response['hrvSummaries']
            : $response;

        $results = [];

        foreach ($items as $item) {
            $entry = PayloadCoercer::asAssocArray($item);

            if ($entry === null) {
                continue;
            }

            $calendarDate = PayloadCoercer::extractDateString($entry['calendarDate'] ?? null);

            if ($calendarDate === null) {
                continue;
            }

            $baseline = PayloadCoercer::asAssocArray($entry['baseline'] ?? null);
            $weeklyAvg = PayloadCoercer::asInt($entry['weeklyAvg'] ?? null);
            $lastNightAvg = PayloadCoercer::asInt($entry['lastNightAvg'] ?? null);
            $lastNight5MinHigh = PayloadCoercer::asInt($entry['lastNight5MinHigh'] ?? null);
            $status = PayloadCoercer::asString($entry['status'] ?? null);

            if ($weeklyAvg === null && $lastNightAvg === null && $lastNight5MinHigh === null && $status === null) {
                continue;
            }

            $results[] = new HrvData(
                provider: self::PROVIDER_ID,
                calendarDate: $calendarDate,
                weeklyAvg: $weeklyAvg,
                lastNightAvg: $lastNightAvg,
                lastNight5minHigh: $lastNight5MinHigh,
                status: $status,
                baselineLowUpper: PayloadCoercer::asInt($baseline['lowUpper'] ?? null),
                baselineBalancedLow: PayloadCoercer::asInt($baseline['balancedLow'] ?? null),
                baselineBalancedUpper: PayloadCoercer::asInt($baseline['balancedUpper'] ?? null),
                feedbackPhrase: PayloadCoercer::asString($entry['feedbackPhrase'] ?? null),
            );
        }

        return $results;
    }

    /**
     * Map Garmin daily VO2 max entries into Vo2MaxData DTOs.
     *
     * @param  array<int|string, mixed>  $entries
     * @return list<Vo2MaxData>
     */
    public function mapVo2Max(array $entries): array
    {
        $sportKeys = [
            'generic' => 'running',
            'cycling' => 'cycling',
        ];

        $results = [];

        foreach ($entries as $item) {
            $row = PayloadCoercer::asAssocArray($item);

            if ($row === null) {
                continue;
            }

            foreach ($sportKeys as $payloadKey => $sport) {
                $entry = PayloadCoercer::asAssocArray($row[$payloadKey] ?? null);

                if ($entry === null) {
                    continue;
                }

                $calendarDate = PayloadCoercer::extractDateString($entry['calendarDate'] ?? null);
                $precise = PayloadCoercer::asFloat($entry['vo2MaxPreciseValue'] ?? null);
                $value = PayloadCoercer::asFloat($entry['vo2MaxValue'] ?? null);

                if ($calendarDate === null || ($precise === null && $value === null)) {
                    continue;
                }

                $results[] = new Vo2MaxData(
                    provider: self::PROVIDER_ID,
                    calendarDate: $calendarDate,
                    sport: $sport,
                    vo2MaxPrecise: $precise,
                    vo2Max: $value,
                    subSport: PayloadCoercer::asString($entry['subSport'] ?? null),
                );
            }
        }

        return $results;
    }

    /**
     * Map a Garmin fitness age payload into a FitnessAgeData DTO.
     *
     * @param  array<string, mixed>  $raw
     */
    public function mapFitnessAge(array $raw, string $date): ?FitnessAgeData
    {
        $chronologicalAge = PayloadCoercer::asFloat($raw['chronologicalAge'] ?? null);
        $fitnessAge = PayloadCoercer::asFloat($raw['fitnessAge'] ?? null);
        $previousFitnessAge = PayloadCoercer::asFloat($raw['previousFitnessAge'] ?? null);

        if ($chronologicalAge === null && $fitnessAge === null) {
            return null;
        }

        return new FitnessAgeData(
            provider: self::PROVIDER_ID,
            calendarDate: $date,
            chronologicalAge: $chronologicalAge,
            fitnessAge: $fitnessAge,
            previousFitnessAge: $previousFitnessAge,
            components: PayloadCoercer::asAssocArray($raw['components'] ?? null),
        );
    }

    /**
     * Map a Garmin weight range response into WeightMeasurementData DTOs.
     *
     * @param  array<string, mixed>  $response
     * @return list<WeightMeasurementData>
     */
    public function mapWeightMeasurements(array $response, string $timezone): array
    {
        $summaries = PayloadCoercer::asArray($response['dailyWeightSummaries'] ?? null);

        if ($summaries === null) {
            return [];
        }

        $results = [];

        foreach ($summaries as $summaryItem) {
            $summary = PayloadCoercer::asAssocArray($summaryItem);

            if ($summary === null) {
                continue;
            }

            $summaryDate = PayloadCoercer::extractDateString($summary['summaryDate'] ?? null);
            $metrics = PayloadCoercer::asArray($summary['allWeightMetrics'] ?? null);

            if ($metrics === null || $metrics === []) {
                $latest = PayloadCoercer::asAssocArray($summary['latestWeight'] ?? null);
                $metrics = $latest !== null ? [$latest] : [];
            }

            foreach ($metrics as $metricItem) {
                $metric = PayloadCoercer::asAssocArray($metricItem);

                if ($metric === null) {
                    continue;
                }

                $samplePk = PayloadCoercer::asInt($metric['samplePk'] ?? null);
                $weightGrams = PayloadCoercer::asInt($metric['weight'] ?? null);

                if ($samplePk === null || $samplePk <= 0 || $weightGrams === null || $weightGrams <= 0) {
                    continue;
                }

                $measuredAt = PayloadCoercer::timestampMsToUtc($metric['timestampGMT'] ?? null)
                    ?? PayloadCoercer::timestampMsToUtc($metric['date'] ?? null);

                $calendarDate = PayloadCoercer::extractDateString($metric['calendarDate'] ?? null)
                    ?? $summaryDate
                    ?? $measuredAt?->setTimezone($timezone)->format('Y-m-d');

                if ($calendarDate === null) {
                    continue;
                }

                $results[] = new WeightMeasurementData(
                    provider: self::PROVIDER_ID,
                    externalId: (string) $samplePk,
                    calendarDate: $calendarDate,
                    weightGrams: $weightGrams,
                    measuredAt: $measuredAt,
                    bmi: PayloadCoercer::asFloat($metric['bmi'] ?? null),
                    bodyFatPct: PayloadCoercer::asFloat($metric['bodyFat'] ?? null),
                    bodyWaterPct: PayloadCoercer::asFloat($metric['bodyWater'] ?? null),
                    boneMassGrams: PayloadCoercer::asInt($metric['boneMass'] ?? null),
                    muscleMassGrams: PayloadCoercer::asInt($metric['muscleMass'] ?? null),
                    sourceType: PayloadCoercer::asString($metric['sourceType'] ?? null),
                );
            }
        }

        return $results;
    }

    /**
     * Map Garmin effective weight goal payloads into WeightGoalData DTOs.
     *
     * @param  array<int|string, mixed>  $response
     * @return list<WeightGoalData>
     */
    public function mapWeightGoals(array $response): array
    {
        $goals = isset($response['userGoals']) && is_array($response['userGoals'])
            ? $response['userGoals']
            : $response;

        $results = [];

        foreach ($goals as $item) {
            $goal = PayloadCoercer::asAssocArray($item);

            if ($goal === null) {
                continue;
            }

            $goalType = strtoupper((string) ($goal['userGoalType'] ?? ''));

            if ($goalType !== '' && $goalType !== 'WEIGHT_GRAMS') {
                continue;
            }

            $goalStartDate = PayloadCoercer::extractDateString($goal['startDate'] ?? null);
            $goalGrams = PayloadCoercer::asInt($goal['goalValue'] ?? null);

            if ($goalStartDate === null || $goalGrams === null || $goalGrams <= 0) {
                continue;
            }

            $results[] = new WeightGoalData(
                provider: self::PROVIDER_ID,
                startDate: $goalStartDate,
                goalGrams: $goalGrams,
                endDate: PayloadCoercer::extractDateString($goal['endDate'] ?? null),
                sourceCategory: PayloadCoercer::asString($goal['userGoalCategory'] ?? null),
                updatedAt: PayloadCoercer::parseDatetime($goal['updateDate'] ?? null, 'UTC'),
            );
        }

        return $results;
    }

    /**
     * Map Garmin heart rate zone payloads into HeartRateZoneData DTOs.
     *
     * @param  array<int|string, mixed>  $entries
     * @return list<HeartRateZoneData>
     */
    public function mapHeartRateZones(array $entries): array
    {
        $results = [];

        foreach ($entries as $item) {
            $zone = PayloadCoercer::asAssocArray($item);

            if ($zone === null) {
                continue;
            }

            $sport = PayloadCoercer::asString($zone['sport'] ?? null);

            if ($sport === null) {
                continue;
            }

            $results[] = new HeartRateZoneData(
                provider: self::PROVIDER_ID,
                sport: $sport,
                trainingMethod: PayloadCoercer::asString($zone['trainingMethod'] ?? null),
                maxHr: PayloadCoercer::asInt($zone['maxHeartRateUsed'] ?? null),
                restingHr: PayloadCoercer::asInt($zone['restingHeartRateUsed'] ?? null),
                lactateThresholdHr: PayloadCoercer::asInt($zone['lactateThresholdHeartRateUsed'] ?? null),
                zone1Floor: PayloadCoercer::asInt($zone['zone1Floor'] ?? null),
                zone2Floor: PayloadCoercer::asInt($zone['zone2Floor'] ?? null),
                zone3Floor: PayloadCoercer::asInt($zone['zone3Floor'] ?? null),
                zone4Floor: PayloadCoercer::asInt($zone['zone4Floor'] ?? null),
                zone5Floor: PayloadCoercer::asInt($zone['zone5Floor'] ?? null),
            );
        }

        return $results;
    }

    /**
     * Extract extended summary columns already present in stored Garmin raw_summary JSON.
     *
     * @param  array<int|string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    public function extractExtendedSummaryFromRaw(?array $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $updates = [
            'avg_grade_adjusted_speed' => is_numeric($raw['avgGradeAdjustedSpeed'] ?? null) ? (float) $raw['avgGradeAdjustedSpeed'] : null,
            'bmr_calories' => is_numeric($raw['bmrCalories'] ?? null) ? (int) round((float) $raw['bmrCalories']) : null,
            'sweat_loss_ml' => is_numeric($raw['waterEstimated'] ?? null) ? (int) round((float) $raw['waterEstimated']) : null,
            'avg_vertical_oscillation' => is_numeric($raw['avgVerticalOscillation'] ?? null) ? (float) $raw['avgVerticalOscillation'] : null,
            'avg_vertical_ratio' => is_numeric($raw['avgVerticalRatio'] ?? null) ? (float) $raw['avgVerticalRatio'] : null,
            'avg_ground_contact_time' => is_numeric($raw['avgGroundContactTime'] ?? null) ? (float) $raw['avgGroundContactTime'] : null,
            'aerobic_te_message' => is_string($raw['aerobicTrainingEffectMessage'] ?? null) ? $raw['aerobicTrainingEffectMessage'] : null,
            'anaerobic_te_message' => is_string($raw['anaerobicTrainingEffectMessage'] ?? null) ? $raw['anaerobicTrainingEffectMessage'] : null,
            'avg_respiration_rate' => is_numeric($raw['avgRespirationRate'] ?? null) ? (float) $raw['avgRespirationRate'] : null,
            'min_respiration_rate' => is_numeric($raw['minRespirationRate'] ?? null) ? (float) $raw['minRespirationRate'] : null,
            'max_respiration_rate' => is_numeric($raw['maxRespirationRate'] ?? null) ? (float) $raw['maxRespirationRate'] : null,
            'min_temperature' => is_numeric($raw['minTemperature'] ?? null) ? (float) $raw['minTemperature'] : null,
            'max_temperature' => is_numeric($raw['maxTemperature'] ?? null) ? (float) $raw['maxTemperature'] : null,
            'moderate_intensity_minutes' => is_numeric($raw['moderateIntensityMinutes'] ?? null) ? (int) round((float) $raw['moderateIntensityMinutes']) : null,
            'vigorous_intensity_minutes' => is_numeric($raw['vigorousIntensityMinutes'] ?? null) ? (int) round((float) $raw['vigorousIntensityMinutes']) : null,
        ];

        if (isset($raw['splitSummaries']) && is_array($raw['splitSummaries'])) {
            foreach ($raw['splitSummaries'] as $split) {
                if (! is_array($split) || ! is_string($split['splitType'] ?? null) || ! is_numeric($split['duration'] ?? null)) {
                    continue;
                }

                $duration = round((float) $split['duration'], 2);

                if ($split['splitType'] === 'RWD_RUN') {
                    $updates['run_duration_seconds'] = $duration;
                } elseif ($split['splitType'] === 'RWD_WALK') {
                    $updates['walk_duration_seconds'] = $duration;
                } elseif ($split['splitType'] === 'RWD_STAND') {
                    $updates['idle_duration_seconds'] = $duration;
                }
            }
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<array{distance_key: string, distance_meters: float, elapsed_seconds: float, source: string}>
     */
    private function extractSeededSplits(array $raw, string $sportCategory, bool $isIndoor): array
    {
        $splitMap = [];

        if ($sportCategory === 'running' && ! $isIndoor) {
            $splitMap = self::RUNNING_SPLIT_MAP;
        } elseif ($sportCategory === 'biking') {
            // Emit fastestSplit_40000 for any biking activity so host apps can apply discipline_override.
            $splitMap = self::BIKING_SPLIT_MAP;
        }

        $seeded = [];

        foreach ($splitMap as $payloadKey => $meta) {
            $elapsedSeconds = PayloadCoercer::asFloat($raw[$payloadKey] ?? null);

            if ($elapsedSeconds === null || $elapsedSeconds <= 0.0) {
                continue;
            }

            $seeded[] = [
                'distance_key' => $meta['distance_key'],
                'distance_meters' => $meta['distance_meters'],
                'elapsed_seconds' => $elapsedSeconds,
                'source' => 'garmin_split',
            ];
        }

        return $seeded;
    }

    private function hasDailyMetricValues(DailyHealthMetricData $dto): bool
    {
        $positiveValues = [
            $dto->steps,
            $dto->distanceMeters,
            $dto->activeCalories,
            $dto->moderateIntensityMinutes,
            $dto->vigorousIntensityMinutes,
            $dto->floorsAscended,
        ];

        foreach ($positiveValues as $value) {
            if ($value !== null && $value > 0) {
                return true;
            }
        }

        $presentValues = [
            $dto->restingHr,
            $dto->minHr,
            $dto->maxHr,
            $dto->avgStress,
            $dto->bodyBatteryCurrent,
            $dto->bodyBatteryHigh,
            $dto->bodyBatteryLow,
            $dto->bodyBatteryCharged,
            $dto->bodyBatteryDrained,
            $dto->avgRespiration,
            $dto->avgSpo2,
        ];

        foreach ($presentValues as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $bodyBattery
     */
    private function extractBodyBatterySleepImpact(?array $bodyBattery): ?int
    {
        if ($bodyBattery === null) {
            return null;
        }

        $events = PayloadCoercer::asArray($bodyBattery['activityEvents'] ?? null);

        if ($events === null) {
            return null;
        }

        $sleepImpact = 0;
        $hasSleepEvent = false;

        foreach ($events as $item) {
            $event = PayloadCoercer::asAssocArray($item);

            if ($event === null || strtoupper((string) ($event['eventType'] ?? '')) !== 'SLEEP') {
                continue;
            }

            $impact = PayloadCoercer::asInt($event['bodyBatteryImpact'] ?? null);

            if ($impact !== null) {
                $sleepImpact += $impact;
                $hasSleepEvent = true;
            }
        }

        return $hasSleepEvent ? $sleepImpact : null;
    }

    /**
     * @param  array<int|string, mixed>  $rawValues
     * @return list<array{0: int, 1: int}>
     */
    private function normalizeIntradaySeries(array $rawValues, int $minValue): array
    {
        $filtered = [];

        foreach ($rawValues as $point) {
            if (! is_array($point)) {
                continue;
            }

            $timestampMs = PayloadCoercer::asInt($point[0] ?? null);
            $value = PayloadCoercer::asInt($point[1] ?? null);

            if ($timestampMs === null || $timestampMs <= 0 || $value === null || $value < $minValue) {
                continue;
            }

            $filtered[] = [$timestampMs, $value];
        }

        $count = count($filtered);

        if ($count <= self::MAX_INTRADAY_SERIES_POINTS) {
            return $filtered;
        }

        $step = (int) ceil($count / self::MAX_INTRADAY_SERIES_POINTS);
        $downsampled = [];

        for ($index = 0; $index < $count; $index += $step) {
            $downsampled[] = $filtered[$index];
        }

        $lastPoint = $filtered[$count - 1];

        if ($downsampled[count($downsampled) - 1] !== $lastPoint) {
            $downsampled[] = $lastPoint;
        }

        return $downsampled;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<int, float>|null
     */
    private function extractPowerCurve(array $raw): ?array
    {
        $curve = [];

        foreach (self::POWER_CURVE_DURATIONS as $seconds) {
            $value = PayloadCoercer::asFloat($raw["maxAvgPower_{$seconds}"] ?? null);

            if ($value !== null && $value > 0.0) {
                $curve[$seconds] = $value;
            }
        }

        return $curve !== [] ? $curve : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractUnpavedPercentage(array $raw): ?float
    {
        $splits = PayloadCoercer::asArray($raw['splitSummaries'] ?? null);

        if ($splits === null) {
            return null;
        }

        $paved = 0.0;
        $unpaved = 0.0;
        $hasSurfaceData = false;

        foreach ($splits as $splitItem) {
            $split = PayloadCoercer::asAssocArray($splitItem);

            if ($split === null) {
                continue;
            }

            $splitType = PayloadCoercer::asString($split['splitType'] ?? null);
            $distance = PayloadCoercer::asFloat($split['distance'] ?? null);

            if ($distance === null || $distance < 0.0) {
                continue;
            }

            if ($splitType === 'SURFACE_TYPE_UNPAVED') {
                $unpaved += $distance;
                $hasSurfaceData = true;
            } elseif ($splitType === 'SURFACE_TYPE_PAVED') {
                $paved += $distance;
                $hasSurfaceData = true;
            }
        }

        $total = $paved + $unpaved;

        if (! $hasSurfaceData || $total <= 0.0) {
            return null;
        }

        return round(($unpaved / $total) * 100.0, 1);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function resolveActivityStartTimes(array $raw, string $timezone): array
    {
        $local = PayloadCoercer::parseDatetime($raw['startTimeLocal'] ?? null, $timezone);
        $utc = PayloadCoercer::parseDatetime($raw['startTimeGMT'] ?? null, 'UTC');

        if ($utc === null && isset($raw['beginTimestamp'])) {
            $utc = PayloadCoercer::timestampMsToUtc($raw['beginTimestamp']);
        }

        if ($local === null && $utc !== null) {
            $local = $utc->setTimezone($timezone);
        }

        if ($utc === null && $local !== null) {
            $utc = $local->setTimezone('UTC');
        }

        return [$local, $utc];
    }
}
