<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

final readonly class HeartRateZoneData
{
    public function __construct(
        public string $provider,
        public string $sport,
        public ?string $trainingMethod = null,
        public ?int $maxHr = null,
        public ?int $restingHr = null,
        public ?int $lactateThresholdHr = null,
        public ?int $zone1Floor = null,
        public ?int $zone2Floor = null,
        public ?int $zone3Floor = null,
        public ?int $zone4Floor = null,
        public ?int $zone5Floor = null,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     sport: string,
     *     training_method: ?string,
     *     max_hr: ?int,
     *     resting_hr: ?int,
     *     lactate_threshold_hr: ?int,
     *     zone1_floor: ?int,
     *     zone2_floor: ?int,
     *     zone3_floor: ?int,
     *     zone4_floor: ?int,
     *     zone5_floor: ?int
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'sport' => $this->sport,
            'training_method' => $this->trainingMethod,
            'max_hr' => $this->maxHr,
            'resting_hr' => $this->restingHr,
            'lactate_threshold_hr' => $this->lactateThresholdHr,
            'zone1_floor' => $this->zone1Floor,
            'zone2_floor' => $this->zone2Floor,
            'zone3_floor' => $this->zone3Floor,
            'zone4_floor' => $this->zone4Floor,
            'zone5_floor' => $this->zone5Floor,
        ];
    }
}
