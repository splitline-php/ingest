<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

/**
 * Canonical parsed telemetry stream returned by binary FIT and activity stream parsers.
 *
 * @phpstan-type FitRecord array{
 *     time: float,
 *     distance: ?float,
 *     altitude: ?float,
 *     speed: ?float,
 *     hr: ?int,
 *     cadence: ?int,
 *     power: ?int,
 *     lat: ?float,
 *     lng: ?float,
 *     gap_speed?: float,
 *     stamina?: int,
 *     stamina_potential?: int,
 *     performance_condition?: int,
 *     stride_length?: float,
 *     vertical_oscillation?: float,
 *     vertical_ratio?: float,
 *     gct?: float,
 *     respiration?: float,
 *     temperature?: float,
 *     left_balance?: float,
 *     left_te?: float,
 *     right_te?: float,
 *     left_ps?: float,
 *     right_ps?: float,
 *     grit?: float,
 *     flow?: float
 * }
 * @phpstan-type FitSeriesPoint array{
 *     time: float,
 *     distance: ?float,
 *     altitude: ?float,
 *     speed: ?float,
 *     hr: ?int,
 *     cadence: ?int,
 *     power: ?int,
 *     grade: ?float,
 *     lat: ?float,
 *     lng: ?float,
 *     gap_speed?: float,
 *     stamina?: int,
 *     stamina_potential?: int,
 *     performance_condition?: int,
 *     stride_length?: float,
 *     vertical_oscillation?: float,
 *     vertical_ratio?: float,
 *     gct?: float,
 *     respiration?: float,
 *     temperature?: float,
 *     left_balance?: float,
 *     left_te?: float,
 *     right_te?: float,
 *     left_ps?: float,
 *     right_ps?: float,
 *     grit?: float,
 *     flow?: float
 * }
 * @phpstan-type FitLap array{
 *     lap: int,
 *     distance_meters: ?float,
 *     duration_seconds: ?float,
 *     elapsed_seconds: ?float,
 *     avg_speed: ?float,
 *     gap_seconds_per_km: ?float,
 *     avg_hr: ?int,
 *     max_hr: ?int,
 *     avg_cadence: ?int,
 *     avg_power: ?int,
 *     elevation_gain: ?float,
 *     elevation_loss: ?float
 * }
 * @phpstan-type FitKmSplit array{
 *     km: int,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     avg_speed: float,
 *     pace_seconds_per_km: float,
 *     gap_seconds_per_km: ?float,
 *     avg_hr: ?int,
 *     avg_cadence: ?int,
 *     avg_power: ?int,
 *     elevation_gain: float,
 *     elevation_loss: float,
 *     avg_grade: float
 * }
 * @phpstan-type FitRunWalkSegment array{
 *     type: string,
 *     start_time: float,
 *     end_time: float,
 *     duration_seconds: float,
 *     distance_meters: float
 * }
 * @phpstan-type FitJumpEvent array{
 *     time: float,
 *     distance_meters: float,
 *     hang_time_seconds: float,
 *     score: float,
 *     speed: ?float,
 *     lat: ?float,
 *     lng: ?float
 * }
 */
final readonly class ActivityStreamData
{
    /**
     * @param  list<FitRecord>  $records
     * @param  list<FitSeriesPoint>  $series
     * @param  list<array{0: float, 1: float}>  $polyline
     * @param  list<FitLap>  $laps
     * @param  list<FitKmSplit>  $kmSplits
     * @param  list<FitRunWalkSegment>  $runWalkSegments
     * @param  list<FitJumpEvent>  $jumps
     * @param  array<string, mixed>  $sessionSummary
     * @param  array<string, mixed>  $sessionMetadata
     */
    public function __construct(
        public array $records = [],
        public array $series = [],
        public array $polyline = [],
        public array $laps = [],
        public array $kmSplits = [],
        public array $runWalkSegments = [],
        public array $jumps = [],
        public array $sessionSummary = [],
        public array $sessionMetadata = [],
    ) {}

    public function hasHeartRateSamples(int $minSamples = 5, int $minBpm = 30): bool
    {
        $hrCount = 0;

        foreach ($this->records as $record) {
            if (isset($record['hr']) && $record['hr'] > $minBpm) {
                $hrCount++;

                if ($hrCount >= $minSamples) {
                    return true;
                }
            }
        }

        return $hrCount >= $minSamples;
    }

    public function heartRateCoverageRatio(int $minBpm = 30): float
    {
        $total = count($this->records);

        if ($total === 0) {
            return 0.0;
        }

        $hrCount = 0;

        foreach ($this->records as $record) {
            if (isset($record['hr']) && $record['hr'] > $minBpm) {
                $hrCount++;
            }
        }

        return $hrCount / $total;
    }

    /**
     * @return array{
     *     series: list<FitSeriesPoint>,
     *     polyline: list<array{0: float, 1: float}>,
     *     laps: list<FitLap>,
     *     km_splits: list<FitKmSplit>,
     *     run_walk_segments: list<FitRunWalkSegment>,
     *     jumps: list<FitJumpEvent>
     * }
     */
    public function toArray(): array
    {
        return [
            'series' => $this->series,
            'polyline' => $this->polyline,
            'laps' => $this->laps,
            'km_splits' => $this->kmSplits,
            'run_walk_segments' => $this->runWalkSegments,
            'jumps' => $this->jumps,
        ];
    }
}
