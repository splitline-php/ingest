<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

use Carbon\CarbonImmutable;

final readonly class SleepData
{
    /**
     * @param  array<int|string, mixed>|null  $rawPayload
     */
    public function __construct(
        public string $provider,
        public string $calendarDate,
        public ?int $sleepSeconds = null,
        public ?int $deepSeconds = null,
        public ?int $lightSeconds = null,
        public ?int $remSeconds = null,
        public ?int $awakeSeconds = null,
        public ?int $napSeconds = null,
        public ?CarbonImmutable $sleepStartLocal = null,
        public ?CarbonImmutable $sleepEndLocal = null,
        public ?int $sleepScore = null,
        public ?int $sleepNeedMinutes = null,
        public ?int $nextSleepNeedMinutes = null,
        public ?array $rawPayload = null,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     calendar_date: string,
     *     sleep_seconds: ?int,
     *     deep_seconds: ?int,
     *     light_seconds: ?int,
     *     rem_seconds: ?int,
     *     awake_seconds: ?int,
     *     nap_seconds: ?int,
     *     sleep_start_local: ?CarbonImmutable,
     *     sleep_end_local: ?CarbonImmutable,
     *     sleep_score: ?int,
     *     sleep_need_minutes: ?int,
     *     next_sleep_need_minutes: ?int,
     *     raw_payload: array<int|string, mixed>|null
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'calendar_date' => $this->calendarDate,
            'sleep_seconds' => $this->sleepSeconds,
            'deep_seconds' => $this->deepSeconds,
            'light_seconds' => $this->lightSeconds,
            'rem_seconds' => $this->remSeconds,
            'awake_seconds' => $this->awakeSeconds,
            'nap_seconds' => $this->napSeconds,
            'sleep_start_local' => $this->sleepStartLocal,
            'sleep_end_local' => $this->sleepEndLocal,
            'sleep_score' => $this->sleepScore,
            'sleep_need_minutes' => $this->sleepNeedMinutes,
            'next_sleep_need_minutes' => $this->nextSleepNeedMinutes,
            'raw_payload' => $this->rawPayload,
        ];
    }
}
