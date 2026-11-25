<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

use Carbon\CarbonImmutable;

final readonly class WeightMeasurementData
{
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $calendarDate,
        public int $weightGrams,
        public ?CarbonImmutable $measuredAt = null,
        public ?float $bmi = null,
        public ?float $bodyFatPct = null,
        public ?float $bodyWaterPct = null,
        public ?int $boneMassGrams = null,
        public ?int $muscleMassGrams = null,
        public ?string $sourceType = null,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     external_id: string,
     *     calendar_date: string,
     *     measured_at: ?CarbonImmutable,
     *     weight_grams: int,
     *     bmi: ?float,
     *     body_fat_pct: ?float,
     *     body_water_pct: ?float,
     *     bone_mass_grams: ?int,
     *     muscle_mass_grams: ?int,
     *     source_type: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'external_id' => $this->externalId,
            'calendar_date' => $this->calendarDate,
            'measured_at' => $this->measuredAt,
            'weight_grams' => $this->weightGrams,
            'bmi' => $this->bmi,
            'body_fat_pct' => $this->bodyFatPct,
            'body_water_pct' => $this->bodyWaterPct,
            'bone_mass_grams' => $this->boneMassGrams,
            'muscle_mass_grams' => $this->muscleMassGrams,
            'source_type' => $this->sourceType,
        ];
    }
}
