<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

use Carbon\CarbonImmutable;

final readonly class WeightGoalData
{
    public function __construct(
        public string $provider,
        public string $startDate,
        public int $goalGrams,
        public ?string $endDate = null,
        public ?string $sourceCategory = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     start_date: string,
     *     end_date: ?string,
     *     goal_grams: int,
     *     source_category: ?string,
     *     updated_at: ?CarbonImmutable
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'goal_grams' => $this->goalGrams,
            'source_category' => $this->sourceCategory,
            'updated_at' => $this->updatedAt,
        ];
    }
}
