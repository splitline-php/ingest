<?php

declare(strict_types=1);

namespace Splitline\Weather;

use Carbon\CarbonImmutable;
use Splitline\Core\Data\ActivityWeatherData;
use Splitline\Core\Data\RouteInput;
use Splitline\Core\Data\WeatherSampleData;

/**
 * Pure weather enrichment orchestrator: waypoint sampling, Open-Meteo retrieval,
 * temporal/circular interpolation, summary aggregation, and route wind impact calculation.
 *
 * @phpstan-import-type OpenMeteoHourlySeries from OpenMeteoClient
 * @phpstan-import-type SampledWaypoint from ActivityWaypointSampler
 */
class WeatherEnricher
{
    public const string PROVIDER = 'open_meteo';

    public function __construct(
        private readonly ActivityWaypointSampler $sampler,
        private readonly OpenMeteoClient $client,
        private readonly WindVectorMath $math = new WindVectorMath,
    ) {}

    /**
     * Determine whether an activity route is eligible for weather enrichment.
     */
    public function isEligible(RouteInput $input): bool
    {
        if ($input->isIndoor) {
            return false;
        }

        if ($input->startLat !== null && $input->startLng !== null) {
            return true;
        }

        return $this->sampler->hasValidGnssPoints($input->series);
    }

    /**
     * Fetch, interpolate, summarize, and return weather telemetry for an outdoor activity.
     */
    public function enrich(RouteInput $input): ?ActivityWeatherData
    {
        if (! $this->isEligible($input)) {
            return null;
        }

        $sampled = $this->sampler->sample($input);
        $waypoints = $sampled['waypoints'];
        $samplingMode = $sampled['mode'];

        if ($waypoints === []) {
            return null;
        }

        /** @var list<array{lat: float, lng: float}> $uniqueCoordinates */
        $uniqueCoordinates = [];
        /** @var array<string, int> $coordKeyToIndex */
        $coordKeyToIndex = [];
        /** @var list<int> $waypointToCoordIndex */
        $waypointToCoordIndex = [];
        $lastWaypointTimestamp = $waypoints[0]['timestamp'];

        foreach ($waypoints as $waypoint) {
            if ($waypoint['timestamp'] > $lastWaypointTimestamp) {
                $lastWaypointTimestamp = $waypoint['timestamp'];
            }

            $coordKey = sprintf('%.5f,%.5f', $waypoint['lat'], $waypoint['lng']);

            if (! isset($coordKeyToIndex[$coordKey])) {
                $coordKeyToIndex[$coordKey] = count($uniqueCoordinates);
                $uniqueCoordinates[] = [
                    'lat' => $waypoint['lat'],
                    'lng' => $waypoint['lng'],
                ];
            }

            $waypointToCoordIndex[] = $coordKeyToIndex[$coordKey];
        }

        if ($uniqueCoordinates === []) {
            return null;
        }

        $windowStartUtc = $input->startedAtUtc->utc();
        $durationSeconds = max(0, (int) round($input->elapsedDurationSeconds ?? $input->durationSeconds));
        $windowEndUtc = CarbonImmutable::createFromTimestampUTC(
            max($lastWaypointTimestamp, $windowStartUtc->getTimestamp() + $durationSeconds),
        );

        $response = $this->client->fetchHourly($uniqueCoordinates, $windowStartUtc, $windowEndUtc);

        /** @var list<WeatherSampleData> $samples */
        $samples = [];

        foreach ($waypoints as $index => $waypoint) {
            $locationSeries = $response['locations'][$waypointToCoordIndex[$index]];
            $samples[] = $this->interpolateWaypointSample($waypoint, $locationSeries);
        }

        $summary = $this->summarizeSamples($samples);

        $windImpact = $samplingMode === 'route' && $input->series !== null && $input->series !== []
            ? $this->math->computeRouteWindImpact(
                $input->series,
                array_map(
                    static fn (WeatherSampleData $sample): array => $sample->toArray(),
                    $samples,
                ),
            )
            : [
                'headwind_pct' => null,
                'crosswind_pct' => null,
                'tailwind_pct' => null,
                'avg_effective_wind_kmh' => null,
            ];

        return new ActivityWeatherData(
            provider: self::PROVIDER,
            modelEndpoint: $response['endpoint'],
            samplingMode: $samplingMode,
            waypointCount: count($samples),
            condition: $summary['condition'],
            icon: $summary['icon'],
            wmoCode: $summary['wmo_code'],
            baseCondition: $summary['base_condition'],
            temperatureC: $summary['temperature_c'],
            minTemperatureC: $summary['min_temperature_c'],
            maxTemperatureC: $summary['max_temperature_c'],
            apparentTemperatureC: $summary['apparent_temperature_c'],
            humidityPct: $summary['humidity_pct'],
            dewPointC: $summary['dew_point_c'],
            windSpeedKmh: $summary['wind_speed_kmh'],
            windGustsKmh: $summary['wind_gusts_kmh'],
            windDirectionDeg: $summary['wind_direction_deg'],
            windDirectionCompass: $summary['wind_direction_compass'],
            precipitationMm: $summary['precipitation_mm'],
            cloudCoverPct: $summary['cloud_cover_pct'],
            surfacePressureHpa: $summary['surface_pressure_hpa'],
            headwindPct: $windImpact['headwind_pct'],
            crosswindPct: $windImpact['crosswind_pct'],
            tailwindPct: $windImpact['tailwind_pct'],
            avgEffectiveWindKmh: $windImpact['avg_effective_wind_kmh'],
            samples: $samples,
        );
    }

    /**
     * Interpolate scalar and circular wind metrics for a single waypoint from hourly Open-Meteo series.
     *
     * @param  SampledWaypoint  $waypoint
     * @param  OpenMeteoHourlySeries  $hourly
     */
    private function interpolateWaypointSample(array $waypoint, array $hourly): WeatherSampleData
    {
        $times = $hourly['time'];

        if ($times === []) {
            return new WeatherSampleData(
                timeOffsetS: $waypoint['time_offset_s'],
                distanceM: round($waypoint['distance_m'], 1),
                lat: round($waypoint['lat'], 6),
                lng: round($waypoint['lng'], 6),
            );
        }

        [$idx0, $idx1, $alpha] = $this->findBracketingHours($times, $waypoint['timestamp']);

        $temperature = $this->interpolateScalar($hourly['temperature_2m'], $idx0, $idx1, $alpha);
        $apparentTemp = $this->interpolateScalar($hourly['apparent_temperature'], $idx0, $idx1, $alpha);
        $humidity = $this->interpolateScalar($hourly['relative_humidity_2m'], $idx0, $idx1, $alpha);
        $dewPoint = $this->interpolateScalar($hourly['dew_point_2m'], $idx0, $idx1, $alpha);
        $windGusts = $this->interpolateScalar($hourly['wind_gusts_10m'], $idx0, $idx1, $alpha);
        $precipitation = $this->interpolateScalar($hourly['precipitation'], $idx0, $idx1, $alpha);
        $cloudCover = $this->interpolateScalar($hourly['cloud_cover'], $idx0, $idx1, $alpha);
        $pressure = $this->interpolateScalar($hourly['surface_pressure'], $idx0, $idx1, $alpha);

        $speed0 = $hourly['wind_speed_10m'][$idx0] ?? null;
        $speed1 = $hourly['wind_speed_10m'][$idx1] ?? null;
        $dir0 = $hourly['wind_direction_10m'][$idx0] ?? null;
        $dir1 = $hourly['wind_direction_10m'][$idx1] ?? null;

        $windSpeed = null;
        $windDirDeg = null;
        $windCompass = null;

        if ($speed0 !== null && $dir0 !== null && $speed1 !== null && $dir1 !== null) {
            $interpolatedWind = $this->math->interpolateWindVector($speed0, $dir0, $speed1, $dir1, $alpha);
            $windSpeed = round($interpolatedWind['speed'], 1);
            $windDirDeg = $interpolatedWind['direction_deg'];
            $windCompass = $this->math->compassDirection((float) $windDirDeg);
        } elseif ($speed0 !== null && $dir0 !== null) {
            $windSpeed = round($speed0, 1);
            $windDirDeg = ((int) round(fmod(fmod($dir0, 360.0) + 360.0, 360.0))) % 360;
            $windCompass = $this->math->compassDirection((float) $windDirDeg);
        } elseif ($speed1 !== null && $dir1 !== null) {
            $windSpeed = round($speed1, 1);
            $windDirDeg = ((int) round(fmod(fmod($dir1, 360.0) + 360.0, 360.0))) % 360;
            $windCompass = $this->math->compassDirection((float) $windDirDeg);
        }

        $nearestIdx = $alpha < 0.5 ? $idx0 : $idx1;
        $otherIdx = $alpha < 0.5 ? $idx1 : $idx0;
        $wmoCode = $hourly['weather_code'][$nearestIdx] ?? $hourly['weather_code'][$otherIdx] ?? null;

        return new WeatherSampleData(
            timeOffsetS: $waypoint['time_offset_s'],
            distanceM: round($waypoint['distance_m'], 1),
            lat: round($waypoint['lat'], 6),
            lng: round($waypoint['lng'], 6),
            wmoCode: $wmoCode,
            temperatureC: $temperature !== null ? round($temperature, 1) : null,
            apparentTemperatureC: $apparentTemp !== null ? round($apparentTemp, 1) : null,
            humidityPct: $humidity !== null ? max(0, min(100, (int) round($humidity))) : null,
            dewPointC: $dewPoint !== null ? round($dewPoint, 1) : null,
            windSpeedKmh: $windSpeed,
            windGustsKmh: $windGusts !== null ? round($windGusts, 1) : null,
            windDirectionDeg: $windDirDeg,
            windDirectionCompass: $windCompass,
            precipitationMm: $precipitation !== null ? round($precipitation, 2) : null,
            cloudCoverPct: $cloudCover !== null ? max(0, min(100, (int) round($cloudCover))) : null,
            surfacePressureHpa: $pressure !== null ? round($pressure, 1) : null,
        );
    }

    /**
     * Find the bracketing hourly index pair and interpolation fraction alpha in [0, 1].
     *
     * @param  non-empty-list<int>  $times
     * @return array{0: int, 1: int, 2: float}
     */
    private function findBracketingHours(array $times, int $timestamp): array
    {
        $lastIndex = count($times) - 1;

        if ($lastIndex === 0 || $timestamp <= $times[0]) {
            return [0, 0, 0.0];
        }

        if ($timestamp >= $times[$lastIndex]) {
            return [$lastIndex, $lastIndex, 0.0];
        }

        for ($i = 0; $i < $lastIndex; $i++) {
            $t0 = $times[$i];
            $t1 = $times[$i + 1];

            if ($timestamp >= $t0 && $timestamp <= $t1) {
                $span = $t1 - $t0;
                $alpha = $span > 0 ? ($timestamp - $t0) / $span : 0.0;

                return [$i, $i + 1, $alpha];
            }
        }

        return [$lastIndex, $lastIndex, 0.0];
    }

    /**
     * @param  list<?float>  $series
     */
    private function interpolateScalar(array $series, int $idx0, int $idx1, float $alpha): ?float
    {
        $v0 = $series[$idx0] ?? null;
        $v1 = $series[$idx1] ?? null;

        if ($v0 !== null && $v1 !== null) {
            return ((1.0 - $alpha) * $v0) + ($alpha * $v1);
        }

        return $v0 ?? $v1;
    }

    /**
     * Aggregate waypoint samples into activity-level summary values.
     *
     * @param  list<WeatherSampleData>  $samples
     * @return array{
     *     wmo_code: ?int,
     *     condition: string,
     *     base_condition: ?string,
     *     icon: string,
     *     temperature_c: ?float,
     *     min_temperature_c: ?float,
     *     max_temperature_c: ?float,
     *     apparent_temperature_c: ?float,
     *     humidity_pct: ?int,
     *     dew_point_c: ?float,
     *     wind_speed_kmh: ?float,
     *     wind_gusts_kmh: ?float,
     *     wind_direction_deg: ?int,
     *     wind_direction_compass: ?string,
     *     precipitation_mm: ?float,
     *     cloud_cover_pct: ?int,
     *     surface_pressure_hpa: ?float
     * }
     */
    private function summarizeSamples(array $samples): array
    {
        $temps = $this->extractNonNullFloats($samples, 'temperatureC');
        $apparentTemps = $this->extractNonNullFloats($samples, 'apparentTemperatureC');
        $humidities = $this->extractNonNullFloats($samples, 'humidityPct');
        $dewPoints = $this->extractNonNullFloats($samples, 'dewPointC');
        $windGusts = $this->extractNonNullFloats($samples, 'windGustsKmh');
        $precipitations = $this->extractNonNullFloats($samples, 'precipitationMm');
        $cloudCovers = $this->extractNonNullFloats($samples, 'cloudCoverPct');
        $pressures = $this->extractNonNullFloats($samples, 'surfacePressureHpa');

        $windSamples = [];

        foreach ($samples as $sample) {
            if ($sample->windSpeedKmh !== null && $sample->windDirectionDeg !== null) {
                $windSamples[] = [
                    'wind_speed_kmh' => $sample->windSpeedKmh,
                    'wind_direction_deg' => $sample->windDirectionDeg,
                ];
            }
        }

        $windSpeedKmh = null;
        $windDirectionDeg = null;
        $windDirectionCompass = null;

        if ($windSamples !== []) {
            $vectorMean = $this->math->vectorMeanWind($windSamples);
            $windSpeedKmh = round($vectorMean['speed'], 1);
            $windDirectionDeg = $vectorMean['direction_deg'];
            $windDirectionCompass = $this->math->compassDirection((float) $windDirectionDeg);
        }

        $maxWindGusts = $windGusts !== [] ? round(max($windGusts), 1) : null;
        $wmoCode = $this->resolveRepresentativeWmoCode($samples);

        if ($wmoCode !== null) {
            $resolved = $this->math->resolveDisplayCondition($wmoCode, $windSpeedKmh, $maxWindGusts);
            $condition = $resolved['condition'];
            $baseCondition = $resolved['base_condition'];
            $icon = $resolved['icon'];
        } else {
            $condition = 'Unknown';
            $baseCondition = null;
            $icon = 'cloud';
        }

        return [
            'wmo_code' => $wmoCode,
            'condition' => $condition,
            'base_condition' => $baseCondition,
            'icon' => $icon,
            'temperature_c' => $temps !== [] ? round(array_sum($temps) / count($temps), 1) : null,
            'min_temperature_c' => $temps !== [] ? round(min($temps), 1) : null,
            'max_temperature_c' => $temps !== [] ? round(max($temps), 1) : null,
            'apparent_temperature_c' => $apparentTemps !== [] ? round(array_sum($apparentTemps) / count($apparentTemps), 1) : null,
            'humidity_pct' => $humidities !== [] ? max(0, min(100, (int) round(array_sum($humidities) / count($humidities)))) : null,
            'dew_point_c' => $dewPoints !== [] ? round(array_sum($dewPoints) / count($dewPoints), 1) : null,
            'wind_speed_kmh' => $windSpeedKmh,
            'wind_gusts_kmh' => $maxWindGusts,
            'wind_direction_deg' => $windDirectionDeg,
            'wind_direction_compass' => $windDirectionCompass,
            'precipitation_mm' => $precipitations !== [] ? round(array_sum($precipitations) / count($precipitations), 2) : null,
            'cloud_cover_pct' => $cloudCovers !== [] ? max(0, min(100, (int) round(array_sum($cloudCovers) / count($cloudCovers)))) : null,
            'surface_pressure_hpa' => $pressures !== [] ? round(array_sum($pressures) / count($pressures), 1) : null,
        ];
    }

    /**
     * @param  list<WeatherSampleData>  $samples
     * @param  'temperatureC'|'apparentTemperatureC'|'humidityPct'|'dewPointC'|'windGustsKmh'|'precipitationMm'|'cloudCoverPct'|'surfacePressureHpa'  $property
     * @return list<float>
     */
    private function extractNonNullFloats(array $samples, string $property): array
    {
        $values = [];

        foreach ($samples as $sample) {
            $value = match ($property) {
                'temperatureC' => $sample->temperatureC,
                'apparentTemperatureC' => $sample->apparentTemperatureC,
                'humidityPct' => $sample->humidityPct,
                'dewPointC' => $sample->dewPointC,
                'windGustsKmh' => $sample->windGustsKmh,
                'precipitationMm' => $sample->precipitationMm,
                'cloudCoverPct' => $sample->cloudCoverPct,
                'surfacePressureHpa' => $sample->surfacePressureHpa,
            };

            if ($value !== null) {
                $values[] = (float) $value;
            }
        }

        return $values;
    }

    /**
     * Select the dominant WMO code across samples, breaking ties toward higher severity.
     *
     * @param  list<WeatherSampleData>  $samples
     */
    private function resolveRepresentativeWmoCode(array $samples): ?int
    {
        /** @var array<int, int> $counts */
        $counts = [];

        foreach ($samples as $sample) {
            $code = $sample->wmoCode;

            if ($code !== null) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        $bestCode = null;
        $bestCount = 0;

        foreach ($counts as $code => $count) {
            if ($count > $bestCount || ($count === $bestCount && ($bestCode === null || $code > $bestCode))) {
                $bestCode = $code;
                $bestCount = $count;
            }
        }

        return $bestCode;
    }
}
