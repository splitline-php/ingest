<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

final readonly class HrvData
{
    public function __construct(
        public string $provider,
        public string $calendarDate,
        public ?int $weeklyAvg = null,
        public ?int $lastNightAvg = null,
        public ?int $lastNight5minHigh = null,
        public ?string $status = null,
        public ?int $baselineLowUpper = null,
        public ?int $baselineBalancedLow = null,
        public ?int $baselineBalancedUpper = null,
        public ?string $feedbackPhrase = null,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     calendar_date: string,
     *     weekly_avg: ?int,
     *     last_night_avg: ?int,
     *     last_night_5min_high: ?int,
     *     status: ?string,
     *     baseline_low_upper: ?int,
     *     baseline_balanced_low: ?int,
     *     baseline_balanced_upper: ?int,
     *     feedback_phrase: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'calendar_date' => $this->calendarDate,
            'weekly_avg' => $this->weeklyAvg,
            'last_night_avg' => $this->lastNightAvg,
            'last_night_5min_high' => $this->lastNight5minHigh,
            'status' => $this->status,
            'baseline_low_upper' => $this->baselineLowUpper,
            'baseline_balanced_low' => $this->baselineBalancedLow,
            'baseline_balanced_upper' => $this->baselineBalancedUpper,
            'feedback_phrase' => $this->feedbackPhrase,
        ];
    }
}
