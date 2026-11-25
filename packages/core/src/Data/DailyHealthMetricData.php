<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

final readonly class DailyHealthMetricData
{
    /**
     * @param  array<int|string, mixed>|null  $heartRateSeries
     * @param  array<int|string, mixed>|null  $bodyBatterySeries
     * @param  array<int|string, mixed>|null  $rawPayload
     * @param  list<string>  $fillOnlyWhenNullFields
     */
    public function __construct(
        public string $provider,
        public string $calendarDate,
        public ?int $dayDurationSeconds = null,
        public ?int $steps = null,
        public ?int $stepGoal = null,
        public ?float $distanceMeters = null,
        public ?int $totalCalories = null,
        public ?int $activeCalories = null,
        public ?int $bmrCalories = null,
        public ?int $restingHr = null,
        public ?int $sevenDayAvgRhr = null,
        public ?int $minHr = null,
        public ?int $maxHr = null,
        public ?int $avgStress = null,
        public ?int $maxStress = null,
        public ?string $stressQualifier = null,
        public ?int $restStressSeconds = null,
        public ?int $lowStressSeconds = null,
        public ?int $mediumStressSeconds = null,
        public ?int $highStressSeconds = null,
        public ?int $bodyBatteryCurrent = null,
        public ?int $bodyBatteryHigh = null,
        public ?int $bodyBatteryLow = null,
        public ?int $bodyBatteryCharged = null,
        public ?int $bodyBatteryDrained = null,
        public ?int $bodyBatterySleep = null,
        public ?int $moderateIntensityMinutes = null,
        public ?int $vigorousIntensityMinutes = null,
        public ?int $intensityMinutesGoal = null,
        public ?float $floorsAscended = null,
        public ?float $floorsDescended = null,
        public ?int $floorsGoal = null,
        public ?float $avgRespiration = null,
        public ?float $minRespiration = null,
        public ?float $maxRespiration = null,
        public ?float $avgSpo2 = null,
        public ?float $lowestSpo2 = null,
        public ?float $latestSpo2 = null,
        public ?int $hydrationMl = null,
        public ?array $heartRateSeries = null,
        public ?array $bodyBatterySeries = null,
        public ?array $rawPayload = null,
        public bool $partialUpdate = false,
        public array $fillOnlyWhenNullFields = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'calendar_date' => $this->calendarDate,
            'day_duration_seconds' => $this->dayDurationSeconds,
            'steps' => $this->steps,
            'step_goal' => $this->stepGoal,
            'distance_meters' => $this->distanceMeters,
            'total_calories' => $this->totalCalories,
            'active_calories' => $this->activeCalories,
            'bmr_calories' => $this->bmrCalories,
            'resting_hr' => $this->restingHr,
            'seven_day_avg_rhr' => $this->sevenDayAvgRhr,
            'min_hr' => $this->minHr,
            'max_hr' => $this->maxHr,
            'avg_stress' => $this->avgStress,
            'max_stress' => $this->maxStress,
            'stress_qualifier' => $this->stressQualifier,
            'rest_stress_seconds' => $this->restStressSeconds,
            'low_stress_seconds' => $this->lowStressSeconds,
            'medium_stress_seconds' => $this->mediumStressSeconds,
            'high_stress_seconds' => $this->highStressSeconds,
            'body_battery_current' => $this->bodyBatteryCurrent,
            'body_battery_high' => $this->bodyBatteryHigh,
            'body_battery_low' => $this->bodyBatteryLow,
            'body_battery_charged' => $this->bodyBatteryCharged,
            'body_battery_drained' => $this->bodyBatteryDrained,
            'body_battery_sleep' => $this->bodyBatterySleep,
            'moderate_intensity_minutes' => $this->moderateIntensityMinutes,
            'vigorous_intensity_minutes' => $this->vigorousIntensityMinutes,
            'intensity_minutes_goal' => $this->intensityMinutesGoal,
            'floors_ascended' => $this->floorsAscended,
            'floors_descended' => $this->floorsDescended,
            'floors_goal' => $this->floorsGoal,
            'avg_respiration' => $this->avgRespiration,
            'min_respiration' => $this->minRespiration,
            'max_respiration' => $this->maxRespiration,
            'avg_spo2' => $this->avgSpo2,
            'lowest_spo2' => $this->lowestSpo2,
            'latest_spo2' => $this->latestSpo2,
            'hydration_ml' => $this->hydrationMl,
            'heart_rate_series' => $this->heartRateSeries,
            'body_battery_series' => $this->bodyBatterySeries,
            'raw_payload' => $this->rawPayload,
        ];
    }
}
