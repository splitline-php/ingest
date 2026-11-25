<?php

declare(strict_types=1);

namespace Splitline\Weather;

use Splitline\Core\Data\RouteInput;

/**
 * Model-matched adaptive waypoint sampler for Open-Meteo activity weather queries.
 *
 * @phpstan-type SampledWaypoint array{
 *     time_offset_s: int,
 *     distance_m: float,
 *     lat: float,
 *     lng: float,
 *     timestamp: int
 * }
 */
class ActivityWaypointSampler
{
    public const float DISTANCE_STEP_METERS = 2500.0;

    public const int TIME_STEP_SECONDS = 1200;

    public const float PEAK_ELEVATION_GAIN_THRESHOLD_METERS = 200.0;

    public const float PEAK_MIN_DISTANCE_METERS = 1500.0;

    public const float DEDUP_DISTANCE_METERS = 2000.0;

    public const int MAX_WAYPOINTS = 25;

    public function __construct(
        private readonly WindVectorMath $math = new WindVectorMath,
    ) {}

    /**
     * Determine whether a stream series contains at least one valid GNSS coordinate pair.
     *
     * @param  array<int|string, mixed>|null  $series
     */
    public function hasValidGnssPoints(?array $series): bool
    {
        if ($series === null || $series === []) {
            return false;
        }

        foreach ($series as $point) {
            if (! is_array($point)) {
                continue;
            }

            if ($this->isValidCoordinate($point['lat'] ?? null, $point['lng'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract adaptive route waypoints from a GNSS stream, or fall back to start-point hourly samples.
     *
     * @return array{mode: 'route'|'start_point', waypoints: list<SampledWaypoint>}
     */
    public function sample(RouteInput $input): array
    {
        if ($input->isIndoor) {
            return [
                'mode' => 'start_point',
                'waypoints' => [],
            ];
        }

        $startTimestamp = $input->startedAtUtc->getTimestamp();
        $gnssPoints = $this->extractGnssPoints($input->series, $startTimestamp);

        if ($gnssPoints !== []) {
            return [
                'mode' => 'route',
                'waypoints' => $this->sampleRouteWaypoints($input, $gnssPoints),
            ];
        }

        return [
            'mode' => 'start_point',
            'waypoints' => $this->sampleStartPointWaypoints($input, $startTimestamp),
        ];
    }

    /**
     * Extract and normalize valid GNSS points from an activity stream series.
     *
     * @param  array<int|string, mixed>|null  $series
     * @return list<array{index: int, time_offset_s: int, distance_m: float, altitude: ?float, lat: float, lng: float, timestamp: int}>
     */
    private function extractGnssPoints(?array $series, int $startTimestamp): array
    {
        if ($series === null || $series === []) {
            return [];
        }

        $points = [];
        $cumulativeMeters = 0.0;
        $prevLat = null;
        $prevLng = null;
        $index = 0;

        foreach ($series as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            if (! $this->isValidCoordinate($raw['lat'] ?? null, $raw['lng'] ?? null)) {
                continue;
            }

            $lat = (float) $raw['lat'];
            $lng = (float) $raw['lng'];

            if ($prevLat !== null && $prevLng !== null) {
                $cumulativeMeters += $this->math->haversineMeters($prevLat, $prevLng, $lat, $lng);
            }

            $timeOffsetS = isset($raw['time']) && is_numeric($raw['time'])
                ? max(0, (int) round((float) $raw['time']))
                : 0;

            $distanceM = isset($raw['distance']) && is_numeric($raw['distance'])
                ? max(0.0, (float) $raw['distance'])
                : $cumulativeMeters;

            $altitude = isset($raw['altitude']) && is_numeric($raw['altitude'])
                ? (float) $raw['altitude']
                : null;

            $points[] = [
                'index' => $index,
                'time_offset_s' => $timeOffsetS,
                'distance_m' => round($distanceM, 1),
                'altitude' => $altitude,
                'lat' => $lat,
                'lng' => $lng,
                'timestamp' => $startTimestamp + $timeOffsetS,
            ];

            $prevLat = $lat;
            $prevLng = $lng;
            $index++;
        }

        return $points;
    }

    /**
     * Select adaptive route waypoints, peak elevation point, deduplicate, and enforce the 25-waypoint cap.
     *
     * @param  non-empty-list<array{index: int, time_offset_s: int, distance_m: float, altitude: ?float, lat: float, lng: float, timestamp: int}>  $gnssPoints
     * @return list<SampledWaypoint>
     */
    private function sampleRouteWaypoints(RouteInput $input, array $gnssPoints): array
    {
        $count = count($gnssPoints);

        if ($count === 1) {
            return [$this->stripMetadata($gnssPoints[0])];
        }

        $lastIndex = $count - 1;

        /** @var array<int, array{index: int, time_offset_s: int, distance_m: float, altitude: ?float, lat: float, lng: float, timestamp: int, is_peak: bool}> $selectedByIndex */
        $selectedByIndex = [];
        $selectedByIndex[0] = [...$gnssPoints[0], 'is_peak' => false];
        $lastSelected = $gnssPoints[0];

        for ($i = 1; $i < $lastIndex; $i++) {
            $candidate = $gnssPoints[$i];
            $distDelta = $candidate['distance_m'] - $lastSelected['distance_m'];
            $timeDelta = $candidate['time_offset_s'] - $lastSelected['time_offset_s'];

            if ($distDelta >= self::DISTANCE_STEP_METERS || $timeDelta >= self::TIME_STEP_SECONDS) {
                $selectedByIndex[$i] = [...$candidate, 'is_peak' => false];
                $lastSelected = $candidate;
            }
        }

        $selectedByIndex[$lastIndex] = [...$gnssPoints[$lastIndex], 'is_peak' => false];

        $this->maybeInsertPeakElevationWaypoint($input, $gnssPoints, $selectedByIndex);

        ksort($selectedByIndex);
        $candidates = array_values($selectedByIndex);

        $deduplicated = $this->deduplicateCandidates($candidates);
        $capped = $this->enforceWaypointCap($deduplicated);

        return array_map(
            fn (array $wp): array => $this->stripMetadata($wp),
            $capped,
        );
    }

    /**
     * Include the highest-elevation GNSS point when elevation gain >= 200 m and it is >= 1,500 m from existing waypoints.
     *
     * @param  non-empty-list<array{index: int, time_offset_s: int, distance_m: float, altitude: ?float, lat: float, lng: float, timestamp: int}>  $gnssPoints
     * @param  array<int, array{index: int, time_offset_s: int, distance_m: float, altitude: ?float, lat: float, lng: float, timestamp: int, is_peak: bool}>  $selectedByIndex
     */
    private function maybeInsertPeakElevationWaypoint(
        RouteInput $input,
        array $gnssPoints,
        array &$selectedByIndex,
    ): void {
        $elevationGain = $input->elevationGain ?? $this->estimateElevationGain($gnssPoints);

        if ($elevationGain < self::PEAK_ELEVATION_GAIN_THRESHOLD_METERS) {
            return;
        }

        $peakIndex = null;
        $peakAltitude = null;

        foreach ($gnssPoints as $index => $point) {
            if ($point['altitude'] === null) {
                continue;
            }

            if ($peakAltitude === null || $point['altitude'] > $peakAltitude) {
                $peakAltitude = $point['altitude'];
                $peakIndex = $index;
            }
        }

        if ($peakIndex === null) {
            return;
        }

        if (isset($selectedByIndex[$peakIndex])) {
            $selectedByIndex[$peakIndex]['is_peak'] = true;

            return;
        }

        $peakPoint = $gnssPoints[$peakIndex];
        $nearestExistingIndex = null;
        $nearestDistanceMeters = null;

        foreach ($selectedByIndex as $existingIndex => $existing) {
            $distanceMeters = $this->math->haversineMeters(
                $existing['lat'],
                $existing['lng'],
                $peakPoint['lat'],
                $peakPoint['lng'],
            );

            if ($nearestDistanceMeters === null || $distanceMeters < $nearestDistanceMeters) {
                $nearestDistanceMeters = $distanceMeters;
                $nearestExistingIndex = $existingIndex;
            }
        }

        if ($nearestDistanceMeters !== null && $nearestDistanceMeters < self::PEAK_MIN_DISTANCE_METERS) {
            if (isset($selectedByIndex[$nearestExistingIndex])) {
                $selectedByIndex[$nearestExistingIndex]['is_peak'] = true;
            }

            return;
        }

        $selectedByIndex[$peakIndex] = [...$peakPoint, 'is_peak' => true];
    }

    /**
     * Estimate positive elevation gain from stream altitudes when activity-level elevation_gain is unavailable.
     *
     * @param  list<array{altitude: ?float}>  $gnssPoints
     */
    private function estimateElevationGain(array $gnssPoints): float
    {
        $gain = 0.0;
        $prevAltitude = null;

        foreach ($gnssPoints as $point) {
            if ($point['altitude'] === null) {
                continue;
            }

            if ($prevAltitude !== null && $point['altitude'] > $prevAltitude) {
                $gain += $point['altitude'] - $prevAltitude;
            }

            $prevAltitude = $point['altitude'];
        }

        return $gain;
    }

    /**
     * Collapse consecutive candidates within 2,000 m of the previous kept waypoint in the same UTC hour.
     *
     * @param  list<array{index: int, time_offset_s: int, distance_m: float, altitude: ?float, lat: float, lng: float, timestamp: int, is_peak: bool}>  $candidates
     * @return list<array{index: int, time_offset_s: int, distance_m: float, altitude: ?float, lat: float, lng: float, timestamp: int, is_peak: bool}>
     */
    private function deduplicateCandidates(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $kept = [$candidates[0]];
        $count = count($candidates);

        for ($i = 1; $i < $count; $i++) {
            $candidate = $candidates[$i];
            $previous = $kept[count($kept) - 1];

            if (! $candidate['is_peak']) {
                $sameUtcHour = intdiv($candidate['timestamp'], 3600) === intdiv($previous['timestamp'], 3600);
                $distanceMeters = $this->math->haversineMeters(
                    $previous['lat'],
                    $previous['lng'],
                    $candidate['lat'],
                    $candidate['lng'],
                );

                if ($sameUtcHour && $distanceMeters < self::DEDUP_DISTANCE_METERS) {
                    continue;
                }
            }

            $kept[] = $candidate;
        }

        return $kept;
    }

    /**
     * Evenly thin intermediate waypoints when exceeding 25 while preserving start, end, and peak elevation.
     *
     * @param  list<array{index: int, time_offset_s: int, distance_m: float, altitude: ?float, lat: float, lng: float, timestamp: int, is_peak: bool}>  $waypoints
     * @return list<array{index: int, time_offset_s: int, distance_m: float, altitude: ?float, lat: float, lng: float, timestamp: int, is_peak: bool}>
     */
    private function enforceWaypointCap(array $waypoints): array
    {
        $count = count($waypoints);

        if ($count <= self::MAX_WAYPOINTS) {
            return $waypoints;
        }

        $lastIndex = $count - 1;
        $peakPosition = null;

        for ($i = 1; $i < $lastIndex; $i++) {
            if ($waypoints[$i]['is_peak']) {
                $peakPosition = $i;
                break;
            }
        }

        if ($peakPosition === null) {
            $indices = $this->evenlySpacedIndices(0, $lastIndex, self::MAX_WAYPOINTS);
        } else {
            $remainingSlots = self::MAX_WAYPOINTS - 3;
            $leftAvailable = $peakPosition - 1;
            $rightAvailable = $lastIndex - $peakPosition - 1;
            $totalAvailable = $leftAvailable + $rightAvailable;

            $leftSlots = $totalAvailable > 0
                ? (int) round($remainingSlots * ($leftAvailable / $totalAvailable))
                : 0;
            $leftSlots = min($leftAvailable, $leftSlots);
            $rightSlots = min($rightAvailable, $remainingSlots - $leftSlots);

            while ($leftSlots + $rightSlots < $remainingSlots) {
                if ($leftSlots < $leftAvailable) {
                    $leftSlots++;
                } elseif ($rightSlots < $rightAvailable) {
                    $rightSlots++;
                } else {
                    break;
                }
            }

            $leftIndices = $this->pickInteriorIndices(0, $peakPosition, $leftSlots);
            $rightIndices = $this->pickInteriorIndices($peakPosition, $lastIndex, $rightSlots);

            $indices = array_values(array_unique([
                0,
                ...$leftIndices,
                $peakPosition,
                ...$rightIndices,
                $lastIndex,
            ]));
            sort($indices);
        }

        $result = [];

        foreach ($indices as $index) {
            $result[] = $waypoints[$index];
        }

        return $result;
    }

    /**
     * Pick $count evenly spaced integer indices inclusively spanning [$start, $end].
     *
     * @return list<int>
     */
    private function evenlySpacedIndices(int $start, int $end, int $count): array
    {
        $indices = [];
        $steps = max(1, $count - 1);

        for ($step = 0; $step < $count; $step++) {
            $indices[] = $start + (int) round(($step * ($end - $start)) / $steps);
        }

        return array_values(array_unique($indices));
    }

    /**
     * Pick $slots evenly spaced integer indices strictly between ($start, $end).
     *
     * @return list<int>
     */
    private function pickInteriorIndices(int $start, int $end, int $slots): array
    {
        if ($slots <= 0 || ($end - $start) <= 1) {
            return [];
        }

        $indices = [];
        $span = $end - $start;

        for ($k = 1; $k <= $slots; $k++) {
            $candidate = $start + (int) round(($k * $span) / ($slots + 1));
            $candidate = max($start + 1, min($end - 1, $candidate));
            $indices[] = $candidate;
        }

        return array_values(array_unique($indices));
    }

    /**
     * Generate start-point fallback waypoints across [0, duration] (start, each full hour, end).
     *
     * @return list<SampledWaypoint>
     */
    private function sampleStartPointWaypoints(RouteInput $input, int $startTimestamp): array
    {
        if (! $this->isValidCoordinate($input->startLat, $input->startLng)) {
            return [];
        }

        $lat = (float) $input->startLat;
        $lng = (float) $input->startLng;
        $durationSeconds = max(0, (int) round($input->elapsedDurationSeconds ?? $input->durationSeconds));
        $totalDistance = max(0.0, $input->distanceMeters);

        $offsets = [0];

        for ($hourOffset = 3600; $hourOffset < $durationSeconds; $hourOffset += 3600) {
            $offsets[] = $hourOffset;
        }

        if ($durationSeconds > 0) {
            $offsets[] = $durationSeconds;
        }

        if (count($offsets) > self::MAX_WAYPOINTS) {
            $keptIndices = $this->evenlySpacedIndices(0, count($offsets) - 1, self::MAX_WAYPOINTS);
            $thinned = [];

            foreach ($keptIndices as $index) {
                $thinned[] = $offsets[$index];
            }

            $offsets = $thinned;
        }

        $waypoints = [];

        foreach ($offsets as $offset) {
            $distanceM = $durationSeconds > 0
                ? round($totalDistance * ($offset / $durationSeconds), 1)
                : 0.0;

            $waypoints[] = [
                'time_offset_s' => $offset,
                'distance_m' => $distanceM,
                'lat' => $lat,
                'lng' => $lng,
                'timestamp' => $startTimestamp + $offset,
            ];
        }

        return $waypoints;
    }

    /**
     * @param  array{time_offset_s: int, distance_m: float, lat: float, lng: float, timestamp: int}  $waypoint
     * @return SampledWaypoint
     */
    private function stripMetadata(array $waypoint): array
    {
        return [
            'time_offset_s' => $waypoint['time_offset_s'],
            'distance_m' => $waypoint['distance_m'],
            'lat' => $waypoint['lat'],
            'lng' => $waypoint['lng'],
            'timestamp' => $waypoint['timestamp'],
        ];
    }

    private function isValidCoordinate(mixed $lat, mixed $lng): bool
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return false;
        }

        $latFloat = (float) $lat;
        $lngFloat = (float) $lng;

        if (is_nan($latFloat) || is_infinite($latFloat) || is_nan($lngFloat) || is_infinite($lngFloat)) {
            return false;
        }

        return $latFloat >= -90.0 && $latFloat <= 90.0 && $lngFloat >= -180.0 && $lngFloat <= 180.0;
    }
}
