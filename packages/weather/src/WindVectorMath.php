<?php

declare(strict_types=1);

namespace Splitline\Weather;

/**
 * Pure geographic, meteorological, and wind-vector math utilities for activity weather analysis.
 */
class WindVectorMath
{
    private const float EARTH_RADIUS_METERS = 6371000.0;

    private const float CALM_WIND_THRESHOLD_KMH = 3.0;

    private const float WINDY_SPEED_THRESHOLD_KMH = 20.0;

    private const float WINDY_GUSTS_THRESHOLD_KMH = 35.0;

    private const float BEARING_HALF_WINDOW_METERS = 30.0;

    /**
     * @var list<string>
     */
    private const array COMPASS_POINTS = [
        'N',
        'NNE',
        'NE',
        'ENE',
        'E',
        'ESE',
        'SE',
        'SSE',
        'S',
        'SSW',
        'SW',
        'WSW',
        'W',
        'WNW',
        'NW',
        'NNW',
    ];

    /**
     * @var list<int>
     */
    private const array DRY_MILD_WMO_CODES = [0, 1, 2, 3];

    /**
     * Calculate great-circle distance in meters between two GNSS coordinates using the Haversine formula.
     */
    public function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $sinHalfLat = sin($dLat / 2.0);
        $sinHalfLng = sin($dLng / 2.0);

        $a = ($sinHalfLat * $sinHalfLat)
            + (cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * $sinHalfLng * $sinHalfLng);

        $c = 2.0 * asin(min(1.0, sqrt(max(0.0, $a))));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Calculate initial forward azimuth bearing in degrees [0, 360) from point 1 to point 2.
     */
    public function forwardBearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dLambda = deg2rad($lng2 - $lng1);

        $y = sin($dLambda) * cos($phi2);
        $x = (cos($phi1) * sin($phi2)) - (sin($phi1) * cos($phi2) * cos($dLambda));

        $bearing = rad2deg(atan2($y, $x));

        return $this->normalizeDegrees($bearing);
    }

    /**
     * Circularly interpolate two meteorological wind vectors via Cartesian (u, v) components.
     *
     * @return array{speed: float, direction_deg: int}
     */
    public function interpolateWindVector(
        float $speed0,
        float $dirFrom0,
        float $speed1,
        float $dirFrom1,
        float $alpha,
    ): array {
        $t = max(0.0, min(1.0, $alpha));

        $rad0 = deg2rad($this->normalizeDegrees($dirFrom0));
        $rad1 = deg2rad($this->normalizeDegrees($dirFrom1));

        $u0 = -$speed0 * sin($rad0);
        $v0 = -$speed0 * cos($rad0);

        $u1 = -$speed1 * sin($rad1);
        $v1 = -$speed1 * cos($rad1);

        $u = ((1.0 - $t) * $u0) + ($t * $u1);
        $v = ((1.0 - $t) * $v0) + ($t * $v1);

        $speed = hypot($u, $v);

        if ($speed < 1e-9) {
            return [
                'speed' => 0.0,
                'direction_deg' => 0,
            ];
        }

        $direction = $this->normalizeDegrees(rad2deg(atan2(-$u, -$v)));

        return [
            'speed' => round($speed, 2),
            'direction_deg' => ((int) round($direction)) % 360,
        ];
    }

    /**
     * Compute the vector-mean wind speed and meteorological direction across a list of wind samples.
     *
     * @param  list<array{speed?: float|int|null, direction_deg?: float|int|null, wind_speed_kmh?: float|int|null, wind_direction_deg?: float|int|null}>  $samples
     * @return array{speed: float, direction_deg: int}
     */
    public function vectorMeanWind(array $samples): array
    {
        $uSum = 0.0;
        $vSum = 0.0;
        $count = 0;

        foreach ($samples as $sample) {
            $rawSpeed = $sample['speed'] ?? $sample['wind_speed_kmh'] ?? null;
            $rawDirection = $sample['direction_deg'] ?? $sample['wind_direction_deg'] ?? null;

            if ($rawSpeed === null || $rawDirection === null) {
                continue;
            }

            $speed = (float) $rawSpeed;
            $rad = deg2rad($this->normalizeDegrees((float) $rawDirection));

            $uSum += -$speed * sin($rad);
            $vSum += -$speed * cos($rad);
            $count++;
        }

        if ($count === 0) {
            return [
                'speed' => 0.0,
                'direction_deg' => 0,
            ];
        }

        $uMean = $uSum / $count;
        $vMean = $vSum / $count;
        $meanSpeed = hypot($uMean, $vMean);

        if ($meanSpeed < 1e-9) {
            return [
                'speed' => 0.0,
                'direction_deg' => 0,
            ];
        }

        $direction = $this->normalizeDegrees(rad2deg(atan2(-$uMean, -$vMean)));

        return [
            'speed' => round($meanSpeed, 2),
            'direction_deg' => ((int) round($direction)) % 360,
        ];
    }

    /**
     * Map an azimuth angle in degrees to a 16-point compass label (N, NNE, ..., NNW).
     */
    public function compassDirection(float $degrees): string
    {
        $normalized = $this->normalizeDegrees($degrees);
        $index = (int) floor(fmod($normalized + 11.25, 360.0) / 22.5);

        return self::COMPASS_POINTS[$index % 16];
    }

    /**
     * Map a WMO weather interpretation code to its display condition label and Lucide icon key.
     *
     * @return array{condition: string, icon: string}
     */
    public function wmoCondition(int $wmoCode): array
    {
        return match ($wmoCode) {
            0 => ['condition' => 'Clear', 'icon' => 'sun'],
            1 => ['condition' => 'Mostly Clear', 'icon' => 'sun'],
            2 => ['condition' => 'Partly Cloudy', 'icon' => 'cloud-sun'],
            3 => ['condition' => 'Overcast', 'icon' => 'cloud'],
            45, 48 => ['condition' => 'Foggy', 'icon' => 'cloud-fog'],
            51, 53, 55 => ['condition' => 'Drizzle', 'icon' => 'cloud-drizzle'],
            56, 57 => ['condition' => 'Freezing Drizzle', 'icon' => 'cloud-drizzle'],
            61 => ['condition' => 'Light Rain', 'icon' => 'cloud-rain'],
            63 => ['condition' => 'Rain', 'icon' => 'cloud-rain'],
            65 => ['condition' => 'Heavy Rain', 'icon' => 'cloud-rain'],
            66, 67 => ['condition' => 'Freezing Rain', 'icon' => 'cloud-rain'],
            71, 73, 75, 77 => ['condition' => 'Snow', 'icon' => 'snowflake'],
            80, 81, 82 => ['condition' => 'Rain Showers', 'icon' => 'cloud-rain'],
            85, 86 => ['condition' => 'Snow Showers', 'icon' => 'snowflake'],
            95, 96, 99 => ['condition' => 'Thunderstorm', 'icon' => 'cloud-lightning'],
            default => ['condition' => 'Unknown', 'icon' => 'cloud'],
        };
    }

    /**
     * Resolve display weather condition with smart "Windy" override for dry/mild WMO codes.
     *
     * @return array{condition: string, base_condition: string, icon: string}
     */
    public function resolveDisplayCondition(
        int $wmoCode,
        ?float $windSpeedKmh,
        ?float $windGustsKmh,
    ): array {
        $base = $this->wmoCondition($wmoCode);

        $isDryMild = in_array($wmoCode, self::DRY_MILD_WMO_CODES, true);
        $isWindy = ($windSpeedKmh !== null && $windSpeedKmh >= self::WINDY_SPEED_THRESHOLD_KMH)
            || ($windGustsKmh !== null && $windGustsKmh >= self::WINDY_GUSTS_THRESHOLD_KMH);

        if ($isDryMild && $isWindy) {
            return [
                'condition' => 'Windy',
                'base_condition' => $base['condition'],
                'icon' => 'wind',
            ];
        }

        return [
            'condition' => $base['condition'],
            'base_condition' => $base['condition'],
            'icon' => $base['icon'],
        ];
    }

    /**
     * Project meteorological wind onto a route travel bearing into effective tail/head wind and crosswind.
     *
     * Positive effective_kmh indicates tailwind (assisting); negative indicates headwind (opposing).
     *
     * @return array{effective_kmh: float, crosswind_kmh: float, classification: 'headwind'|'crosswind'|'tailwind'|'calm'}
     */
    public function projectEffectiveWind(
        float $windSpeedKmh,
        float $windFromDeg,
        float $routeBearingDeg,
    ): array {
        $windToDeg = $this->normalizeDegrees($windFromDeg + 180.0);
        $normalizedRoute = $this->normalizeDegrees($routeBearingDeg);

        $deltaDeg = fmod($windToDeg - $normalizedRoute + 540.0, 360.0) - 180.0;
        $deltaRad = deg2rad($deltaDeg);

        $effectiveKmh = round($windSpeedKmh * cos($deltaRad), 2);
        $crosswindKmh = round(abs($windSpeedKmh * sin($deltaRad)), 2);

        if ($effectiveKmh === -0.0) {
            $effectiveKmh = 0.0;
        }

        if ($crosswindKmh === -0.0) {
            $crosswindKmh = 0.0;
        }

        if ($windSpeedKmh < self::CALM_WIND_THRESHOLD_KMH) {
            $classification = 'calm';
        } elseif (abs($deltaDeg) <= 45.0) {
            $classification = 'tailwind';
        } elseif (abs($deltaDeg) >= 135.0) {
            $classification = 'headwind';
        } else {
            $classification = 'crosswind';
        }

        return [
            'effective_kmh' => $effectiveKmh,
            'crosswind_kmh' => $crosswindKmh,
            'classification' => $classification,
        ];
    }

    /**
     * Aggregate distance-weighted headwind, crosswind, tailwind percentages and average effective wind
     * across a GNSS stream series using smoothed ~60 m travel bearings.
     *
     * @param  array<int|string, mixed>  $series
     * @param  list<array{time_offset_s: int, distance_m: float, wind_speed_kmh: ?float, wind_direction_deg: ?int}>  $samples
     * @return array{headwind_pct: ?float, crosswind_pct: ?float, tailwind_pct: ?float, avg_effective_wind_kmh: ?float}
     */
    public function computeRouteWindImpact(array $series, array $samples): array
    {
        $nullResult = [
            'headwind_pct' => null,
            'crosswind_pct' => null,
            'tailwind_pct' => null,
            'avg_effective_wind_kmh' => null,
        ];

        $windSamples = [];

        foreach ($samples as $sample) {
            if ($sample['wind_speed_kmh'] !== null && $sample['wind_direction_deg'] !== null) {
                $windSamples[] = [
                    'time_offset_s' => $sample['time_offset_s'],
                    'distance_m' => $sample['distance_m'],
                    'wind_speed_kmh' => $sample['wind_speed_kmh'],
                    'wind_direction_deg' => $sample['wind_direction_deg'],
                ];
            }
        }

        if ($windSamples === []) {
            return $nullResult;
        }

        /** @var list<array{lat: float, lng: float, time: float, distance: float}> $points */
        $points = [];
        $cumulativeMeters = 0.0;
        $prevLat = null;
        $prevLng = null;

        foreach ($series as $point) {
            if (! is_array($point)) {
                continue;
            }

            $rawLat = $point['lat'] ?? null;
            $rawLng = $point['lng'] ?? null;

            if (! is_numeric($rawLat) || ! is_numeric($rawLng)) {
                continue;
            }

            $lat = (float) $rawLat;
            $lng = (float) $rawLng;

            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 || is_nan($lat) || is_nan($lng)) {
                continue;
            }

            if ($prevLat !== null && $prevLng !== null) {
                $cumulativeMeters += $this->haversineMeters($prevLat, $prevLng, $lat, $lng);
            }

            $distance = isset($point['distance']) && is_numeric($point['distance'])
                ? (float) $point['distance']
                : $cumulativeMeters;

            $time = isset($point['time']) && is_numeric($point['time'])
                ? (float) $point['time']
                : 0.0;

            $points[] = [
                'lat' => $lat,
                'lng' => $lng,
                'time' => $time,
                'distance' => $distance,
            ];

            $prevLat = $lat;
            $prevLng = $lng;
        }

        $count = count($points);

        if ($count < 2) {
            return $nullResult;
        }

        $totalDistance = 0.0;
        $classifiedDistance = 0.0;
        $headwindDist = 0.0;
        $crosswindDist = 0.0;
        $tailwindDist = 0.0;
        $weightedEffectiveSum = 0.0;

        for ($i = 0; $i < $count - 1; $i++) {
            $p0 = $points[$i];
            $p1 = $points[$i + 1];

            $segDist = $this->haversineMeters($p0['lat'], $p0['lng'], $p1['lat'], $p1['lng']);

            if ($segDist < 1.0) {
                continue;
            }

            $back = $i;
            while ($back > 0 && ($p0['distance'] - $points[$back]['distance']) < self::BEARING_HALF_WINDOW_METERS) {
                $back--;
            }

            $fwd = $i + 1;
            while ($fwd < $count - 1 && ($points[$fwd]['distance'] - $p1['distance']) < self::BEARING_HALF_WINDOW_METERS) {
                $fwd++;
            }

            $windowSpan = $this->haversineMeters(
                $points[$back]['lat'],
                $points[$back]['lng'],
                $points[$fwd]['lat'],
                $points[$fwd]['lng'],
            );

            if ($windowSpan >= 1.0) {
                $routeBearing = $this->forwardBearing(
                    $points[$back]['lat'],
                    $points[$back]['lng'],
                    $points[$fwd]['lat'],
                    $points[$fwd]['lng'],
                );
            } else {
                $routeBearing = $this->forwardBearing($p0['lat'], $p0['lng'], $p1['lat'], $p1['lng']);
            }

            $midTime = ($p0['time'] + $p1['time']) / 2.0;
            $midDist = ($p0['distance'] + $p1['distance']) / 2.0;
            $wind = $this->interpolateSampleWindAt($windSamples, $midTime, $midDist);

            $projection = $this->projectEffectiveWind($wind['speed'], (float) $wind['direction_deg'], $routeBearing);

            $totalDistance += $segDist;
            $weightedEffectiveSum += $projection['effective_kmh'] * $segDist;

            if ($projection['classification'] !== 'calm') {
                $classifiedDistance += $segDist;

                if ($projection['classification'] === 'headwind') {
                    $headwindDist += $segDist;
                } elseif ($projection['classification'] === 'crosswind') {
                    $crosswindDist += $segDist;
                } else {
                    $tailwindDist += $segDist;
                }
            }
        }

        if ($totalDistance <= 0.0) {
            return $nullResult;
        }

        $avgEffective = round($weightedEffectiveSum / $totalDistance, 1);

        if ($avgEffective === -0.0) {
            $avgEffective = 0.0;
        }

        if ($classifiedDistance <= 0.0) {
            return [
                'headwind_pct' => 0.0,
                'crosswind_pct' => 0.0,
                'tailwind_pct' => 0.0,
                'avg_effective_wind_kmh' => $avgEffective,
            ];
        }

        $headwindPct = round(($headwindDist / $classifiedDistance) * 100.0, 1);
        $crosswindPct = round(($crosswindDist / $classifiedDistance) * 100.0, 1);
        $tailwindPct = round(($tailwindDist / $classifiedDistance) * 100.0, 1);

        $diff = round(100.0 - ($headwindPct + $crosswindPct + $tailwindPct), 1);

        if ($diff !== 0.0) {
            $maxVal = max($headwindPct, $crosswindPct, $tailwindPct);

            if ($headwindPct === $maxVal) {
                $headwindPct = round($headwindPct + $diff, 1);
            } elseif ($crosswindPct === $maxVal) {
                $crosswindPct = round($crosswindPct + $diff, 1);
            } else {
                $tailwindPct = round($tailwindPct + $diff, 1);
            }
        }

        return [
            'headwind_pct' => $headwindPct,
            'crosswind_pct' => $crosswindPct,
            'tailwind_pct' => $tailwindPct,
            'avg_effective_wind_kmh' => $avgEffective,
        ];
    }

    /**
     * Interpolate wind speed and direction at a given elapsed time (or distance) from ordered weather samples.
     *
     * @param  non-empty-list<array{time_offset_s: int, distance_m: float, wind_speed_kmh: float, wind_direction_deg: int}>  $samples
     * @return array{speed: float, direction_deg: int}
     */
    private function interpolateSampleWindAt(array $samples, float $timeOffsetS, float $distanceM): array
    {
        $count = count($samples);

        if ($count === 1) {
            return [
                'speed' => $samples[0]['wind_speed_kmh'],
                'direction_deg' => $samples[0]['wind_direction_deg'],
            ];
        }

        $first = $samples[0];
        $last = $samples[$count - 1];
        $useTime = $last['time_offset_s'] > $first['time_offset_s'];
        $target = $useTime ? $timeOffsetS : $distanceM;
        $firstCoord = $useTime ? (float) $first['time_offset_s'] : $first['distance_m'];
        $lastCoord = $useTime ? (float) $last['time_offset_s'] : $last['distance_m'];

        if ($target <= $firstCoord) {
            return [
                'speed' => $first['wind_speed_kmh'],
                'direction_deg' => $first['wind_direction_deg'],
            ];
        }

        if ($target >= $lastCoord) {
            return [
                'speed' => $last['wind_speed_kmh'],
                'direction_deg' => $last['wind_direction_deg'],
            ];
        }

        for ($i = 0; $i < $count - 1; $i++) {
            $s0 = $samples[$i];
            $s1 = $samples[$i + 1];
            $c0 = $useTime ? (float) $s0['time_offset_s'] : $s0['distance_m'];
            $c1 = $useTime ? (float) $s1['time_offset_s'] : $s1['distance_m'];

            if ($target >= $c0 && $target <= $c1) {
                $span = $c1 - $c0;
                $alpha = $span > 0.0 ? ($target - $c0) / $span : 0.0;

                return $this->interpolateWindVector(
                    $s0['wind_speed_kmh'],
                    (float) $s0['wind_direction_deg'],
                    $s1['wind_speed_kmh'],
                    (float) $s1['wind_direction_deg'],
                    $alpha,
                );
            }
        }

        return [
            'speed' => $last['wind_speed_kmh'],
            'direction_deg' => $last['wind_direction_deg'],
        ];
    }

    private function normalizeDegrees(float $degrees): float
    {
        $normalized = fmod(fmod($degrees, 360.0) + 360.0, 360.0);

        return $normalized === -0.0 ? 0.0 : $normalized;
    }
}
