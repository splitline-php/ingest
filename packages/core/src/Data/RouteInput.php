<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

use Carbon\CarbonImmutable;

final readonly class RouteInput
{
    /**
     * @param  list<array{time?: float|int|null, distance?: float|int|null, altitude?: float|int|null, lat?: float|int|null, lng?: float|int|null}>|null  $series
     */
    public function __construct(
        public bool $isIndoor,
        public CarbonImmutable $startedAtUtc,
        public float $durationSeconds,
        public ?float $elapsedDurationSeconds,
        public float $distanceMeters,
        public ?float $elevationGain,
        public ?float $startLat,
        public ?float $startLng,
        public ?array $series = null,
    ) {}
}
