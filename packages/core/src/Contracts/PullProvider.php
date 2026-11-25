<?php

declare(strict_types=1);

namespace Splitline\Core\Contracts;

use Carbon\CarbonImmutable;
use Splitline\Core\Data\DailyHealthMetricData;
use Splitline\Core\Data\FitnessAgeData;
use Splitline\Core\Data\HeartRateZoneData;
use Splitline\Core\Data\HrvData;
use Splitline\Core\Data\NormalizedActivity;
use Splitline\Core\Data\ProviderContext;
use Splitline\Core\Data\SleepData;
use Splitline\Core\Data\Vo2MaxData;
use Splitline\Core\Data\WeightGoalData;
use Splitline\Core\Data\WeightMeasurementData;

interface PullProvider extends TelemetryProvider
{
    /**
     * @return list<NormalizedActivity>
     */
    public function fetchActivities(
        ProviderContext $context,
        int $offset = 0,
        int $limit = 50,
        ?CarbonImmutable $sinceUtc = null,
    ): array;

    /**
     * @return list<DailyHealthMetricData>
     */
    public function fetchDailyMetrics(
        ProviderContext $context,
        string $startDate,
        string $endDate,
        bool $includeIntraday = false,
    ): array;

    /**
     * @return list<SleepData>
     */
    public function fetchSleep(ProviderContext $context, string $startDate, string $endDate): array;

    /**
     * @return list<HrvData>
     */
    public function fetchHrv(ProviderContext $context, string $startDate, string $endDate): array;

    /**
     * @return list<Vo2MaxData>
     */
    public function fetchVo2Max(ProviderContext $context, string $startDate, string $endDate): array;

    /**
     * @return list<FitnessAgeData>
     */
    public function fetchFitnessAge(
        ProviderContext $context,
        string $startDate,
        string $endDate,
        int $stepDays = 1,
    ): array;

    /**
     * @return list<WeightMeasurementData>
     */
    public function fetchWeightMeasurements(ProviderContext $context, string $startDate, string $endDate): array;

    /**
     * @return list<WeightGoalData>
     */
    public function fetchWeightGoals(ProviderContext $context, string $startDate, string $endDate): array;

    /**
     * @return list<HeartRateZoneData>
     */
    public function fetchHeartRateZones(ProviderContext $context): array;
}
