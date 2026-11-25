<?php

declare(strict_types=1);

namespace Splitline\ProviderFileImport;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Splitline\Core\Analytics\ActivityClassifier;
use Splitline\Core\Contracts\FileImportProvider;
use Splitline\Core\Data\ActivityStreamData;
use Splitline\Core\Data\ImportedActivityBundle;
use Splitline\Core\Data\NormalizedActivity;
use Splitline\Core\Data\ProviderCapabilities;
use Splitline\Core\Support\PayloadCoercer;
use Splitline\Parsers\Exceptions\ParseException;
use Splitline\Parsers\Fit\FitParser;
use Throwable;

/**
 * Standalone .fit and .zip file import provider.
 *
 * @phpstan-import-type FitRecord from ActivityStreamData
 */
class FitFileImportProvider implements FileImportProvider
{
    /**
     * Common FIT SDK manufacturer IDs.
     *
     * @var array<int, string>
     */
    private const array MANUFACTURER_NAMES = [
        1 => 'Garmin',
        15 => 'Dynastream',
        23 => 'Suunto',
        32 => 'Wahoo Fitness',
        38 => 'Magellan',
        40 => 'Bryton',
        76 => 'Mio',
        89 => 'Tacx',
        100 => 'Concept2',
        123 => 'Polar',
        255 => 'Development',
        260 => 'Zwift',
        263 => 'Favero',
        267 => 'Bryton',
        281 => 'TrainerRoad',
        289 => 'Hammerhead',
        294 => 'Coros',
        317 => 'Rouvy',
    ];

    /**
     * Well-known Garmin FIT SDK product IDs.
     *
     * @var array<int, string>
     */
    private const array GARMIN_PRODUCT_NAMES = [
        3121 => 'Edge 530',
        3122 => 'Edge 830',
        3570 => 'Edge 1030 Plus',
        3843 => 'Edge 1040',
        3905 => 'Fenix 7S',
        3906 => 'Fenix 7',
        3907 => 'Fenix 7X',
        3943 => 'Epix Gen 2',
        3990 => 'Forerunner 255 Music',
        3992 => 'Forerunner 255',
        4024 => 'Forerunner 955',
        4061 => 'Edge 540',
        4062 => 'Edge 840',
        4257 => 'Forerunner 965',
        4258 => 'Forerunner 265',
        4315 => 'Forerunner 165',
        4440 => 'Edge 1050',
        4532 => 'Fenix 8',
    ];

    public function __construct(
        private readonly FitParser $parser = new FitParser,
        private readonly ActivityClassifier $classifier = new ActivityClassifier,
    ) {}

    public function id(): string
    {
        return 'fit_upload';
    }

    public function displayName(): string
    {
        return 'FIT File Import';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsActivities: true,
            allowsGroupSharing: true,
        );
    }

    /**
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        return ['fit', 'zip'];
    }

    /**
     * Parse raw `.fit` or `.zip` bytes into a normalized activity bundle.
     *
     * @throws ParseException
     */
    public function importBytes(
        string $bytes,
        string $filename,
        string $timezone = 'UTC',
    ): ImportedActivityBundle {
        $isZip = str_starts_with($bytes, "PK\x03\x04")
            || str_ends_with(strtolower($filename), '.zip');

        if ($isZip) {
            $format = 'fit_zip';
            $innerFitBytes = $this->parser->extractFitFromZip($bytes);
        } else {
            $format = 'fit';
            $innerFitBytes = $bytes;
        }

        $stream = $this->parser->parseBinary($innerFitBytes);
        $externalId = hash('sha256', $innerFitBytes);

        $meta = $stream->sessionMetadata;
        $summary = $stream->sessionSummary;
        $recordStats = $this->computeRecordStats($stream->records);

        $sportId = PayloadCoercer::asInt($meta['sport_id'] ?? null);
        $subSportId = PayloadCoercer::asInt($meta['sub_sport_id'] ?? null);
        $classification = $this->classifier->classifyFitSport($sportId, $subSportId);

        $resolvedTimezone = $this->resolveTimezone($timezone);
        $startedAtUtc = $this->resolveStartedAtUtc($meta, $stream->records);
        $startedAtLocal = $startedAtUtc->setTimezone($resolvedTimezone);

        $distanceMeters = PayloadCoercer::asFloat($meta['total_distance'] ?? null)
            ?? $recordStats['max_distance']
            ?? 0.0;

        $timerDuration = PayloadCoercer::asFloat($meta['total_timer_time'] ?? null);
        $elapsedDuration = PayloadCoercer::asFloat($meta['total_elapsed_time'] ?? null)
            ?? $recordStats['max_time'];
        $durationSeconds = $timerDuration ?? $elapsedDuration ?? 0.0;

        $avgSpeed = PayloadCoercer::asFloat($meta['avg_speed'] ?? null)
            ?? $recordStats['avg_speed']
            ?? ($distanceMeters > 0.0 && $durationSeconds > 0.0 ? round($distanceMeters / $durationSeconds, 3) : null);

        $maxSpeed = PayloadCoercer::asFloat($meta['max_speed'] ?? null)
            ?? $recordStats['max_speed'];

        $elevationGain = PayloadCoercer::asFloat($meta['total_ascent'] ?? null)
            ?? $recordStats['elevation_gain'];

        $elevationLoss = PayloadCoercer::asFloat($meta['total_descent'] ?? null)
            ?? $recordStats['elevation_loss'];

        $manufacturerId = PayloadCoercer::asInt($meta['manufacturer_id'] ?? null);
        $productId = PayloadCoercer::asInt($meta['product_id'] ?? null);

        $beginBodyBattery = PayloadCoercer::asInt($summary['begin_body_battery'] ?? null);
        $endBodyBattery = PayloadCoercer::asInt($summary['end_body_battery'] ?? null);
        $bodyBatteryDiff = PayloadCoercer::asInt($summary['body_battery_diff'] ?? null)
            ?? ($beginBodyBattery !== null && $endBodyBattery !== null ? $endBodyBattery - $beginBodyBattery : null);

        $jumpCount = PayloadCoercer::asInt($summary['jump_count'] ?? null)
            ?? ($stream->jumps !== [] ? count($stream->jumps) : null);

        $providerMetadata = [
            'filename' => $filename,
            'format' => $format,
        ];

        if ($manufacturerId !== null) {
            $providerMetadata['manufacturer_id'] = $manufacturerId;
        }

        if ($productId !== null) {
            $providerMetadata['product_id'] = $productId;
        }

        $activity = new NormalizedActivity(
            provider: $this->id(),
            externalId: $externalId,
            name: $this->formatActivityName($classification['activity_type_key'], $startedAtLocal),
            activityTypeKey: $classification['activity_type_key'],
            sportCategory: $classification['sport_category'],
            sportDiscipline: $classification['sport_discipline'],
            isIndoor: $classification['is_indoor'],
            startedAtLocal: $startedAtLocal,
            startedAtUtc: $startedAtUtc,
            distanceMeters: $distanceMeters,
            durationSeconds: $durationSeconds,
            deviceName: $this->resolveDeviceName($manufacturerId, $productId),
            startLat: $recordStats['start_lat'],
            startLng: $recordStats['start_lng'],
            movingDurationSeconds: $timerDuration,
            elapsedDurationSeconds: $elapsedDuration,
            elevationGain: $elevationGain,
            elevationLoss: $elevationLoss,
            minElevation: $recordStats['min_elevation'],
            maxElevation: $recordStats['max_elevation'],
            avgSpeed: $avgSpeed,
            maxSpeed: $maxSpeed,
            avgGradeAdjustedSpeed: PayloadCoercer::asFloat($summary['avg_grade_adjusted_speed'] ?? null)
                ?? $recordStats['avg_grade_adjusted_speed'],
            calories: PayloadCoercer::asInt($meta['total_calories'] ?? null),
            bmrCalories: PayloadCoercer::asInt($summary['bmr_calories'] ?? null),
            sweatLossMl: PayloadCoercer::asInt($summary['sweat_loss_ml'] ?? null),
            avgHr: PayloadCoercer::asInt($meta['avg_hr'] ?? null) ?? $recordStats['avg_hr'],
            maxHr: PayloadCoercer::asInt($meta['max_hr'] ?? null) ?? $recordStats['max_hr'],
            avgCadence: PayloadCoercer::asFloat($meta['avg_cadence'] ?? null) ?? $recordStats['avg_cadence'],
            maxCadence: PayloadCoercer::asFloat($meta['max_cadence'] ?? null) ?? $recordStats['max_cadence'],
            avgStrideLength: PayloadCoercer::asFloat($summary['avg_stride_length'] ?? null)
                ?? $recordStats['avg_stride_length'],
            avgVerticalOscillation: PayloadCoercer::asFloat($summary['avg_vertical_oscillation'] ?? null)
                ?? $recordStats['avg_vertical_oscillation'],
            avgVerticalRatio: PayloadCoercer::asFloat($summary['avg_vertical_ratio'] ?? null)
                ?? $recordStats['avg_vertical_ratio'],
            avgGroundContactTime: PayloadCoercer::asFloat($summary['avg_ground_contact_time'] ?? null)
                ?? $recordStats['avg_ground_contact_time'],
            bodyBatteryDiff: $bodyBatteryDiff,
            beginBodyBattery: $beginBodyBattery,
            endBodyBattery: $endBodyBattery,
            avgPower: PayloadCoercer::asFloat($meta['avg_power'] ?? null) ?? $recordStats['avg_power'],
            maxPower: PayloadCoercer::asFloat($meta['max_power'] ?? null) ?? $recordStats['max_power'],
            normalizedPower: PayloadCoercer::asFloat($summary['normalized_power'] ?? null),
            thresholdPower: PayloadCoercer::asFloat($summary['threshold_power'] ?? null),
            tss: PayloadCoercer::asFloat($summary['tss'] ?? null),
            intensityFactor: PayloadCoercer::asFloat($summary['intensity_factor'] ?? null),
            totalWork: PayloadCoercer::asFloat($summary['total_work'] ?? null),
            leftBalance: PayloadCoercer::asFloat($summary['left_balance'] ?? null)
                ?? $recordStats['left_balance'],
            avgLeftTorqueEffectiveness: PayloadCoercer::asFloat($summary['avg_left_torque_effectiveness'] ?? null)
                ?? $recordStats['avg_left_torque_effectiveness'],
            avgRightTorqueEffectiveness: PayloadCoercer::asFloat($summary['avg_right_torque_effectiveness'] ?? null)
                ?? $recordStats['avg_right_torque_effectiveness'],
            avgLeftPedalSmoothness: PayloadCoercer::asFloat($summary['avg_left_pedal_smoothness'] ?? null)
                ?? $recordStats['avg_left_pedal_smoothness'],
            avgRightPedalSmoothness: PayloadCoercer::asFloat($summary['avg_right_pedal_smoothness'] ?? null)
                ?? $recordStats['avg_right_pedal_smoothness'],
            powerZoneBounds: $this->extractIntList($summary['power_zone_bounds'] ?? null),
            windEnabled: $this->extractBool($summary['wind_enabled'] ?? null),
            grit: PayloadCoercer::asFloat($summary['grit'] ?? null),
            avgFlow: PayloadCoercer::asFloat($summary['avg_flow'] ?? null)
                ?? $recordStats['avg_flow'],
            jumpCount: $jumpCount,
            workoutFeel: PayloadCoercer::asInt($summary['workout_feel'] ?? null),
            workoutRpe: PayloadCoercer::asInt($summary['workout_rpe'] ?? null),
            executionScore: PayloadCoercer::asInt($summary['execution_score'] ?? null),
            workoutMeta: PayloadCoercer::asAssocArray($summary['workout_meta'] ?? null),
            beginStamina: PayloadCoercer::asInt($summary['begin_stamina'] ?? null),
            endStamina: PayloadCoercer::asInt($summary['end_stamina'] ?? null),
            minStamina: PayloadCoercer::asInt($summary['min_stamina'] ?? null),
            avgRespirationRate: PayloadCoercer::asFloat($summary['avg_respiration_rate'] ?? null)
                ?? $recordStats['avg_respiration_rate'],
            minRespirationRate: PayloadCoercer::asFloat($summary['min_respiration_rate'] ?? null)
                ?? $recordStats['min_respiration_rate'],
            maxRespirationRate: PayloadCoercer::asFloat($summary['max_respiration_rate'] ?? null)
                ?? $recordStats['max_respiration_rate'],
            avgTemperature: PayloadCoercer::asFloat($summary['avg_temperature'] ?? null)
                ?? $recordStats['avg_temperature'],
            minTemperature: PayloadCoercer::asFloat($summary['min_temperature'] ?? null)
                ?? $recordStats['min_temperature'],
            maxTemperature: PayloadCoercer::asFloat($summary['max_temperature'] ?? null)
                ?? $recordStats['max_temperature'],
            runDurationSeconds: PayloadCoercer::asFloat($summary['run_duration_seconds'] ?? null),
            walkDurationSeconds: PayloadCoercer::asFloat($summary['walk_duration_seconds'] ?? null),
            idleDurationSeconds: PayloadCoercer::asFloat($summary['idle_duration_seconds'] ?? null),
            providerMetadata: $providerMetadata,
        );

        return new ImportedActivityBundle(
            activity: $activity,
            stream: $stream,
            rawBytes: $bytes,
            format: $format,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<FitRecord>  $records
     */
    private function resolveStartedAtUtc(array $meta, array $records): CarbonImmutable
    {
        $metaStart = PayloadCoercer::parseDatetime($meta['start_timestamp_utc'] ?? null, 'UTC');

        if ($metaStart !== null) {
            return $metaStart->utc();
        }

        if ($records !== []) {
            $recordStart = $this->extractRecordTimestamp($records[0]);

            if ($recordStart !== null) {
                return $recordStart;
            }
        }

        return CarbonImmutable::now('UTC');
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function extractRecordTimestamp(array $record): ?CarbonImmutable
    {
        if (! isset($record['timestamp'])) {
            return null;
        }

        $value = $record['timestamp'];

        if (is_int($value) || is_float($value)) {
            $timestamp = (int) $value;

            return $timestamp > 0 ? CarbonImmutable::createFromTimestampUTC($timestamp) : null;
        }

        return PayloadCoercer::parseDatetime($value, 'UTC')?->utc();
    }

    private function resolveTimezone(string $timezone): DateTimeZone
    {
        $trimmed = trim($timezone);

        if ($trimmed === '') {
            return new DateTimeZone('UTC');
        }

        try {
            return new DateTimeZone($trimmed);
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    private function formatActivityName(string $activityTypeKey, CarbonImmutable $startedAtLocal): string
    {
        $normalized = trim(str_replace('_', ' ', $activityTypeKey));
        $sportLabel = $normalized !== '' ? ucwords($normalized) : 'Activity';

        return "{$sportLabel} {$startedAtLocal->format('Y-m-d')}";
    }

    private function resolveDeviceName(?int $manufacturerId, ?int $productId): ?string
    {
        if ($manufacturerId === null || ! isset(self::MANUFACTURER_NAMES[$manufacturerId])) {
            return null;
        }

        $manufacturer = self::MANUFACTURER_NAMES[$manufacturerId];

        if ($manufacturerId === 1 && $productId !== null && isset(self::GARMIN_PRODUCT_NAMES[$productId])) {
            return "{$manufacturer} ".self::GARMIN_PRODUCT_NAMES[$productId];
        }

        return $manufacturer;
    }

    /**
     * @param  list<FitRecord>  $records
     * @return array{
     *     max_distance: ?float,
     *     max_time: ?float,
     *     start_lat: ?float,
     *     start_lng: ?float,
     *     elevation_gain: ?float,
     *     elevation_loss: ?float,
     *     min_elevation: ?float,
     *     max_elevation: ?float,
     *     avg_hr: ?int,
     *     max_hr: ?int,
     *     avg_speed: ?float,
     *     max_speed: ?float,
     *     avg_power: ?float,
     *     max_power: ?float,
     *     avg_cadence: ?float,
     *     max_cadence: ?float,
     *     avg_vertical_oscillation: ?float,
     *     avg_vertical_ratio: ?float,
     *     avg_ground_contact_time: ?float,
     *     avg_stride_length: ?float,
     *     avg_grade_adjusted_speed: ?float,
     *     left_balance: ?float,
     *     avg_left_torque_effectiveness: ?float,
     *     avg_right_torque_effectiveness: ?float,
     *     avg_left_pedal_smoothness: ?float,
     *     avg_right_pedal_smoothness: ?float,
     *     avg_flow: ?float,
     *     avg_temperature: ?float,
     *     min_temperature: ?float,
     *     max_temperature: ?float,
     *     avg_respiration_rate: ?float,
     *     min_respiration_rate: ?float,
     *     max_respiration_rate: ?float
     * }
     */
    private function computeRecordStats(array $records): array
    {
        $maxDistance = null;
        $maxTime = null;
        $startLat = null;
        $startLng = null;

        $prevAlt = null;
        $altitudeCount = 0;
        $elevationGain = 0.0;
        $elevationLoss = 0.0;
        $minElevation = null;
        $maxElevation = null;

        $hrSum = 0;
        $hrCount = 0;
        $maxHr = null;

        $speedSum = 0.0;
        $speedCount = 0;
        $maxSpeed = null;

        $powerSum = 0;
        $powerCount = 0;
        $maxPower = null;

        $cadenceSum = 0;
        $cadenceCount = 0;
        $maxCadence = null;

        $vertOscSum = 0.0;
        $vertOscCount = 0;

        $vertRatioSum = 0.0;
        $vertRatioCount = 0;

        $gctSum = 0.0;
        $gctCount = 0;

        $strideSum = 0.0;
        $strideCount = 0;

        $gapSpeedSum = 0.0;
        $gapSpeedCount = 0;

        $leftBalanceSum = 0.0;
        $leftBalanceCount = 0;

        $leftTeSum = 0.0;
        $leftTeCount = 0;

        $rightTeSum = 0.0;
        $rightTeCount = 0;

        $leftPsSum = 0.0;
        $leftPsCount = 0;

        $rightPsSum = 0.0;
        $rightPsCount = 0;

        $flowSum = 0.0;
        $flowCount = 0;

        $tempSum = 0.0;
        $tempCount = 0;
        $minTemp = null;
        $maxTemp = null;

        $respSum = 0.0;
        $respCount = 0;
        $minResp = null;
        $maxResp = null;

        foreach ($records as $record) {
            $time = $record['time'];
            $maxTime = $maxTime === null ? $time : max($maxTime, $time);

            $distance = $record['distance'];
            if ($distance !== null) {
                $maxDistance = $maxDistance === null ? $distance : max($maxDistance, $distance);
            }

            if ($startLat === null && $startLng === null && $record['lat'] !== null && $record['lng'] !== null) {
                $lat = $record['lat'];
                $lng = $record['lng'];

                if ($lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0) {
                    $startLat = $lat;
                    $startLng = $lng;
                }
            }

            $altitude = $record['altitude'];
            if ($altitude !== null) {
                $altitudeCount++;
                $minElevation = $minElevation === null ? $altitude : min($minElevation, $altitude);
                $maxElevation = $maxElevation === null ? $altitude : max($maxElevation, $altitude);

                if ($prevAlt !== null) {
                    $delta = $altitude - $prevAlt;

                    if ($delta > 0.0) {
                        $elevationGain += $delta;
                    } elseif ($delta < 0.0) {
                        $elevationLoss += abs($delta);
                    }
                }

                $prevAlt = $altitude;
            }

            $hr = $record['hr'];
            if ($hr !== null && $hr > 0) {
                $hrSum += $hr;
                $hrCount++;
                $maxHr = $maxHr === null ? $hr : max($maxHr, $hr);
            }

            $speed = $record['speed'];
            if ($speed !== null && $speed >= 0.0) {
                $speedSum += $speed;
                $speedCount++;
                $maxSpeed = $maxSpeed === null ? $speed : max($maxSpeed, $speed);
            }

            $power = $record['power'];
            if ($power !== null && $power >= 0) {
                $powerSum += $power;
                $powerCount++;
                $maxPower = $maxPower === null ? $power : max($maxPower, $power);
            }

            $cadence = $record['cadence'];
            if ($cadence !== null && $cadence > 0) {
                $cadenceSum += $cadence;
                $cadenceCount++;
                $maxCadence = $maxCadence === null ? $cadence : max($maxCadence, $cadence);
            }

            if (isset($record['vertical_oscillation'])) {
                $vertOscSum += $record['vertical_oscillation'];
                $vertOscCount++;
            }

            if (isset($record['vertical_ratio'])) {
                $vertRatioSum += $record['vertical_ratio'];
                $vertRatioCount++;
            }

            if (isset($record['gct'])) {
                $gctSum += $record['gct'];
                $gctCount++;
            }

            if (isset($record['stride_length'])) {
                $strideSum += $record['stride_length'];
                $strideCount++;
            }

            if (isset($record['gap_speed']) && $record['gap_speed'] > 0.0) {
                $gapSpeedSum += $record['gap_speed'];
                $gapSpeedCount++;
            }

            if (isset($record['left_balance'])) {
                $leftBalanceSum += $record['left_balance'];
                $leftBalanceCount++;
            }

            if (isset($record['left_te'])) {
                $leftTeSum += $record['left_te'];
                $leftTeCount++;
            }

            if (isset($record['right_te'])) {
                $rightTeSum += $record['right_te'];
                $rightTeCount++;
            }

            if (isset($record['left_ps'])) {
                $leftPsSum += $record['left_ps'];
                $leftPsCount++;
            }

            if (isset($record['right_ps'])) {
                $rightPsSum += $record['right_ps'];
                $rightPsCount++;
            }

            if (isset($record['flow'])) {
                $flowSum += $record['flow'];
                $flowCount++;
            }

            if (isset($record['temperature'])) {
                $temp = $record['temperature'];
                $tempSum += $temp;
                $tempCount++;
                $minTemp = $minTemp === null ? $temp : min($minTemp, $temp);
                $maxTemp = $maxTemp === null ? $temp : max($maxTemp, $temp);
            }

            if (isset($record['respiration'])) {
                $resp = $record['respiration'];
                $respSum += $resp;
                $respCount++;
                $minResp = $minResp === null ? $resp : min($minResp, $resp);
                $maxResp = $maxResp === null ? $resp : max($maxResp, $resp);
            }
        }

        return [
            'max_distance' => $maxDistance !== null ? round($maxDistance, 2) : null,
            'max_time' => $maxTime !== null ? round($maxTime, 3) : null,
            'start_lat' => $startLat,
            'start_lng' => $startLng,
            'elevation_gain' => $altitudeCount >= 2 ? round($elevationGain, 1) : null,
            'elevation_loss' => $altitudeCount >= 2 ? round($elevationLoss, 1) : null,
            'min_elevation' => $minElevation !== null ? round($minElevation, 2) : null,
            'max_elevation' => $maxElevation !== null ? round($maxElevation, 2) : null,
            'avg_hr' => $hrCount > 0 ? (int) round($hrSum / $hrCount) : null,
            'max_hr' => $maxHr,
            'avg_speed' => $speedCount > 0 ? round($speedSum / $speedCount, 3) : null,
            'max_speed' => $maxSpeed !== null ? round($maxSpeed, 3) : null,
            'avg_power' => $powerCount > 0 ? round($powerSum / $powerCount, 1) : null,
            'max_power' => $maxPower !== null ? round((float) $maxPower, 1) : null,
            'avg_cadence' => $cadenceCount > 0 ? round($cadenceSum / $cadenceCount, 2) : null,
            'max_cadence' => $maxCadence !== null ? round((float) $maxCadence, 2) : null,
            'avg_vertical_oscillation' => $vertOscCount > 0 ? round($vertOscSum / $vertOscCount, 2) : null,
            'avg_vertical_ratio' => $vertRatioCount > 0 ? round($vertRatioSum / $vertRatioCount, 2) : null,
            'avg_ground_contact_time' => $gctCount > 0 ? round($gctSum / $gctCount, 1) : null,
            'avg_stride_length' => $strideCount > 0 ? round($strideSum / $strideCount, 3) : null,
            'avg_grade_adjusted_speed' => $gapSpeedCount > 0 ? round($gapSpeedSum / $gapSpeedCount, 3) : null,
            'left_balance' => $leftBalanceCount > 0 ? round($leftBalanceSum / $leftBalanceCount, 1) : null,
            'avg_left_torque_effectiveness' => $leftTeCount > 0 ? round($leftTeSum / $leftTeCount, 1) : null,
            'avg_right_torque_effectiveness' => $rightTeCount > 0 ? round($rightTeSum / $rightTeCount, 1) : null,
            'avg_left_pedal_smoothness' => $leftPsCount > 0 ? round($leftPsSum / $leftPsCount, 1) : null,
            'avg_right_pedal_smoothness' => $rightPsCount > 0 ? round($rightPsSum / $rightPsCount, 1) : null,
            'avg_flow' => $flowCount > 0 ? round($flowSum / $flowCount, 2) : null,
            'avg_temperature' => $tempCount > 0 ? round($tempSum / $tempCount, 1) : null,
            'min_temperature' => $minTemp !== null ? round($minTemp, 1) : null,
            'max_temperature' => $maxTemp !== null ? round($maxTemp, 1) : null,
            'avg_respiration_rate' => $respCount > 0 ? round($respSum / $respCount, 2) : null,
            'min_respiration_rate' => $minResp !== null ? round($minResp, 2) : null,
            'max_respiration_rate' => $maxResp !== null ? round($maxResp, 2) : null,
        ];
    }

    /**
     * @return list<int>|null
     */
    private function extractIntList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $item) {
            $int = PayloadCoercer::asInt($item);

            if ($int !== null) {
                $result[] = $int;
            }
        }

        return $result !== [] ? $result : null;
    }

    private function extractBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
