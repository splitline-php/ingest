<?php

declare(strict_types=1);

namespace Splitline\Core\Analytics;

use Splitline\Core\Data\ActivityStreamData;

/**
 * Sliding-window Best Efforts calculator using elapsed-time race-clock methodology.
 *
 * @phpstan-import-type FitRecord from ActivityStreamData
 *
 * @phpstan-type StreamRecord array{
 *     time: float,
 *     distance: ?float,
 *     altitude?: ?float,
 *     speed?: ?float,
 *     hr?: ?int,
 *     cadence?: ?int,
 *     power?: ?int,
 *     lat?: ?float,
 *     lng?: ?float
 * }
 * @phpstan-type ComputedBestEffort array{
 *     distance_key: string,
 *     distance_meters: float,
 *     elapsed_seconds: float,
 *     moving_seconds: float,
 *     start_offset_seconds: float,
 *     avg_hr: ?int,
 *     elevation_delta: ?float,
 *     start_distance_meters: float,
 *     end_distance_meters: float
 * }
 */
class BestEffortCalculator
{
    private const float MOVING_SPEED_THRESHOLD_MPS = 0.3;

    /**
     * @var array<string, float>
     */
    public const array RUNNING_DISTANCES = [
        '400m' => 400.0,
        'half_mile' => 804.67,
        '1k' => 1000.0,
        '1mi' => 1609.34,
        '2mi' => 3218.69,
        '5k' => 5000.0,
        '10k' => 10000.0,
        '15k' => 15000.0,
        '10mi' => 16093.44,
        '20k' => 20000.0,
        'half_marathon' => 21097.5,
        '30k' => 30000.0,
        'marathon' => 42195.0,
        '50k' => 50000.0,
    ];

    /**
     * @var array<string, float>
     */
    public const array ROAD_BIKING_DISTANCES = [
        '5k' => 5000.0,
        '10k' => 10000.0,
        '20k' => 20000.0,
        '40k' => 40000.0,
        '50k' => 50000.0,
        '80k' => 80000.0,
        '100k' => 100000.0,
    ];

    /**
     * Compute minimum elapsed time per benchmark distance using an O(N) two-pointer sliding window
     * with linear interpolation at the window end point.
     *
     * @param  list<FitRecord|StreamRecord>  $records
     * @return list<ComputedBestEffort>
     */
    public function calculate(array $records, string $sport): array
    {
        $distances = $this->benchmarkDistancesForSport($sport);

        if ($distances === []) {
            return [];
        }

        /** @var list<array{time: float, distance: float, speed: ?float, hr: ?int, altitude: ?float}> $points */
        $points = [];
        $lastDistance = null;
        $lastTime = null;

        foreach ($records as $record) {
            $distance = $record['distance'];

            if ($distance === null) {
                continue;
            }

            $time = $record['time'];

            if ($lastDistance !== null && $distance < $lastDistance) {
                continue;
            }

            if ($lastTime !== null && $time < $lastTime) {
                continue;
            }

            $points[] = [
                'time' => $time,
                'distance' => $distance,
                'speed' => $record['speed'] ?? null,
                'hr' => $record['hr'] ?? null,
                'altitude' => $record['altitude'] ?? null,
            ];

            $lastDistance = $distance;
            $lastTime = $time;
        }

        $count = count($points);

        if ($count < 2) {
            return [];
        }

        $firstTime = $points[0]['time'];
        $minDistance = $points[0]['distance'];
        $maxDistance = $points[$count - 1]['distance'];
        $totalDistance = $maxDistance - $minDistance;

        /** @var list<float> $cumMoving */
        $cumMoving = [0.0];

        for ($i = 1; $i < $count; $i++) {
            $dt = $points[$i]['time'] - $points[$i - 1]['time'];
            $dd = $points[$i]['distance'] - $points[$i - 1]['distance'];
            $derivedSpeed = $dt > 0.0 ? ($dd / $dt) : 0.0;
            $reportedSpeed = $points[$i]['speed'];

            $isMoving = $dt > 0.0
                && $dd > 0.0
                && (
                    $derivedSpeed > self::MOVING_SPEED_THRESHOLD_MPS
                    || ($dt <= 5.0 && $reportedSpeed !== null && $reportedSpeed > self::MOVING_SPEED_THRESHOLD_MPS)
                );

            $cumMoving[$i] = $cumMoving[$i - 1] + ($isMoving ? $dt : 0.0);
        }

        $results = [];

        foreach ($distances as $distanceKey => $targetDistance) {
            if ($totalDistance < $targetDistance) {
                continue;
            }

            $right = 0;
            $bestElapsed = null;
            $bestMoving = 0.0;
            $bestStartOffset = 0.0;
            $bestLeft = 0;
            $bestRight = 0;
            $bestFraction = 1.0;

            for ($left = 0; $left < $count; $left++) {
                $targetEndDistance = $points[$left]['distance'] + $targetDistance;

                while ($right < $count && $points[$right]['distance'] < $targetEndDistance) {
                    $right++;
                }

                if ($right >= $count) {
                    break;
                }

                $fraction = 1.0;

                if ($right === 0 || $points[$right]['distance'] === $targetEndDistance) {
                    $endTime = $points[$right]['time'];
                    $endMoving = $cumMoving[$right];
                } else {
                    $prevDist = $points[$right - 1]['distance'];
                    $currDist = $points[$right]['distance'];
                    $segmentDist = $currDist - $prevDist;
                    $fraction = $segmentDist > 0.0
                        ? max(0.0, min(1.0, ($targetEndDistance - $prevDist) / $segmentDist))
                        : 0.0;

                    $endTime = $points[$right - 1]['time']
                        + ($fraction * ($points[$right]['time'] - $points[$right - 1]['time']));
                    $endMoving = $cumMoving[$right - 1]
                        + ($fraction * ($cumMoving[$right] - $cumMoving[$right - 1]));
                }

                $elapsed = $endTime - $points[$left]['time'];
                $moving = $endMoving - $cumMoving[$left];

                if ($elapsed > 0.0 && ($bestElapsed === null || $elapsed < $bestElapsed)) {
                    $bestElapsed = $elapsed;
                    $bestMoving = $moving;
                    $bestStartOffset = $points[$left]['time'] - $firstTime;
                    $bestLeft = $left;
                    $bestRight = $right;
                    $bestFraction = $fraction;
                }
            }

            if ($bestElapsed === null) {
                continue;
            }

            $hrSum = 0;
            $hrCount = 0;

            for ($idx = $bestLeft; $idx <= $bestRight; $idx++) {
                $hr = $points[$idx]['hr'];

                if ($hr !== null && $hr > 0) {
                    $hrSum += $hr;
                    $hrCount++;
                }
            }

            $startAlt = $points[$bestLeft]['altitude'];
            $endAlt = null;

            if ($bestRight === 0 || $bestFraction >= 1.0) {
                $endAlt = $points[$bestRight]['altitude'];
            } else {
                $prevAlt = $points[$bestRight - 1]['altitude'];
                $currAlt = $points[$bestRight]['altitude'];

                if ($prevAlt !== null && $currAlt !== null) {
                    $endAlt = $prevAlt + ($bestFraction * ($currAlt - $prevAlt));
                } else {
                    $endAlt = $currAlt ?? $prevAlt;
                }
            }

            $elevDelta = ($startAlt !== null && $endAlt !== null)
                ? round($endAlt - $startAlt, 1)
                : null;

            $startDist = round($points[$bestLeft]['distance'], 2);
            $endDist = round($points[$bestLeft]['distance'] + $targetDistance, 2);

            $results[] = [
                'distance_key' => $distanceKey,
                'distance_meters' => $targetDistance,
                'elapsed_seconds' => round($bestElapsed, 2),
                'moving_seconds' => round(max(0.0, min($bestElapsed, $bestMoving)), 2),
                'start_offset_seconds' => round(max(0.0, $bestStartOffset), 2),
                'avg_hr' => $hrCount > 0 ? (int) round($hrSum / $hrCount) : null,
                'elevation_delta' => $elevDelta,
                'start_distance_meters' => $startDist,
                'end_distance_meters' => $endDist,
            ];
        }

        return $results;
    }

    /**
     * @return array<string, float>
     */
    public function benchmarkDistancesForSport(string $sport): array
    {
        return match ($sport) {
            'running', 'road_running', 'trail_running', 'track_running' => self::RUNNING_DISTANCES,
            'road_biking', 'road_cycling', 'gravel_cycling', 'mtb_cycling', 'ebike_cycling', 'cycling' => self::ROAD_BIKING_DISTANCES,
            default => [],
        };
    }
}
