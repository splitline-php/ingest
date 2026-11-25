<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

final readonly class Vo2MaxData
{
    public function __construct(
        public string $provider,
        public string $calendarDate,
        public string $sport,
        public ?float $vo2MaxPrecise = null,
        public ?float $vo2Max = null,
        public ?string $subSport = null,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     calendar_date: string,
     *     sport: string,
     *     vo2_max_precise: ?float,
     *     vo2_max: ?float,
     *     sub_sport: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'calendar_date' => $this->calendarDate,
            'sport' => $this->sport,
            'vo2_max_precise' => $this->vo2MaxPrecise,
            'vo2_max' => $this->vo2Max,
            'sub_sport' => $this->subSport,
        ];
    }
}
