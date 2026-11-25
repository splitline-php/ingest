<?php

declare(strict_types=1);

namespace Splitline\ProviderGarminPersonal;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Splitline\Core\Contracts\ActivityArchiveProvider;
use Splitline\Core\Contracts\PullProvider;
use Splitline\Core\Data\DailyHealthMetricData;
use Splitline\Core\Data\FitnessAgeData;
use Splitline\Core\Data\HeartRateZoneData;
use Splitline\Core\Data\HrvData;
use Splitline\Core\Data\NormalizedActivity;
use Splitline\Core\Data\ProviderCapabilities;
use Splitline\Core\Data\ProviderContext;
use Splitline\Core\Data\SleepData;
use Splitline\Core\Data\Vo2MaxData;
use Splitline\Core\Data\WeightGoalData;
use Splitline\Core\Data\WeightMeasurementData;
use Splitline\Core\Exceptions\ProviderException;
use Splitline\Core\Support\PayloadCoercer;

final class GarminPersonalProvider implements ActivityArchiveProvider, PullProvider
{
    public const string ID = GarminPersonalMapper::PROVIDER_ID;

    public function __construct(
        private readonly GarminPersonalClient $client,
        private readonly GarminPersonalMapper $mapper = new GarminPersonalMapper,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function displayName(): string
    {
        return 'Garmin Connect (Personal API)';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supportsActivities: true,
            supportsActivityArchives: true,
            supportsDailyMetrics: true,
            supportsSleep: true,
            supportsHrv: true,
            supportsVo2Max: true,
            supportsFitnessAge: true,
            supportsWeight: true,
            supportsHeartRateZones: true,
            supportsWebhooks: false,
            allowsGroupSharing: true,
            requiresCommercialLicense: false,
        );
    }

    public function client(): GarminPersonalClient
    {
        return $this->client;
    }

    public function mapper(): GarminPersonalMapper
    {
        return $this->mapper;
    }

    /**
     * @return list<NormalizedActivity>
     */
    public function fetchActivities(
        ProviderContext $context,
        int $offset = 0,
        int $limit = 50,
        ?CarbonImmutable $sinceUtc = null,
    ): array {
        $rawItems = $this->searchActivitiesRaw($context, [
            'start' => max(0, $offset),
            'limit' => max(1, $limit),
        ]);

        $activities = [];

        foreach ($rawItems as $raw) {
            $activity = $this->mapper->mapActivity($raw, $context->timezone);

            if ($activity === null) {
                continue;
            }

            if ($sinceUtc !== null && $activity->startedAtUtc->lessThan($sinceUtc)) {
                continue;
            }

            $activities[] = $activity;
        }

        return $activities;
    }

    /**
     * @return list<DailyHealthMetricData>
     */
    public function fetchDailyMetrics(
        ProviderContext $context,
        string $startDate,
        string $endDate,
        bool $includeIntraday = false,
    ): array {
        $results = [
            ...$this->fetchDailySummariesV2($context, $startDate, $endDate),
            ...$this->fetchDailyHydration($context, $startDate, $endDate),
        ];

        if ($includeIntraday) {
            $results = [
                ...$results,
                ...$this->fetchDailyBodyBattery($context, $startDate, $endDate),
            ];

            $start = CarbonImmutable::parse($startDate)->startOfDay();
            $cursor = CarbonImmutable::parse($endDate)->startOfDay();

            while ($cursor->greaterThanOrEqualTo($start)) {
                $heartRate = $this->fetchDailyHeartRate($context, $cursor->format('Y-m-d'));

                if ($heartRate !== null) {
                    $results[] = $heartRate;
                }

                $cursor = $cursor->subDay();
            }
        }

        return $results;
    }

    /**
     * @return list<SleepData>
     */
    public function fetchSleep(ProviderContext $context, string $startDate, string $endDate): array
    {
        return $this->fetchSleepSummaries($context, $startDate, $endDate);
    }

    /**
     * @return list<HrvData>
     */
    public function fetchHrv(ProviderContext $context, string $startDate, string $endDate): array
    {
        $response = $this->callOptional(
            fn (): ?array => $this->client->dailyHrvSummaries($context, $startDate, $endDate),
        );

        return $response !== null ? $this->mapper->mapHrvSummaries($response) : [];
    }

    /**
     * @return list<Vo2MaxData>
     */
    public function fetchVo2Max(ProviderContext $context, string $startDate, string $endDate): array
    {
        $entries = $this->callOptional(
            fn (): ?array => $this->client->dailyVo2Max($context, $startDate, $endDate),
        );

        return $entries !== null ? $this->mapper->mapVo2Max($entries) : [];
    }

    /**
     * @return list<FitnessAgeData>
     */
    public function fetchFitnessAge(
        ProviderContext $context,
        string $startDate,
        string $endDate,
        int $stepDays = 1,
    ): array {
        $step = max(1, $stepDays);
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $cursor = CarbonImmutable::parse($endDate)->startOfDay();

        if ($cursor->lessThan($start)) {
            return [];
        }

        $results = [];

        while ($cursor->greaterThanOrEqualTo($start)) {
            $record = $this->fetchFitnessAgeForDate($context, $cursor->format('Y-m-d'));

            if ($record !== null) {
                $results[] = $record;
            }

            $cursor = $cursor->subDays($step);
        }

        return $results;
    }

    /**
     * @return list<WeightMeasurementData>
     */
    public function fetchWeightMeasurements(ProviderContext $context, string $startDate, string $endDate): array
    {
        $response = $this->callOptional(
            fn (): ?array => $this->client->weightMeasurements($context, $startDate, $endDate, includeAll: true),
        );

        return $response !== null ? $this->mapper->mapWeightMeasurements($response, $context->timezone) : [];
    }

    /**
     * @return list<WeightGoalData>
     */
    public function fetchWeightGoals(ProviderContext $context, string $startDate, string $endDate): array
    {
        $response = $this->callOptional(
            fn (): ?array => $this->client->effectiveWeightGoals($context, $startDate, $endDate),
        );

        return $response !== null ? $this->mapper->mapWeightGoals($response) : [];
    }

    /**
     * @return list<HeartRateZoneData>
     */
    public function fetchHeartRateZones(ProviderContext $context): array
    {
        $entries = $this->callOptional(
            fn (): ?array => $this->client->heartRateZones($context),
        );

        return $entries !== null ? $this->mapper->mapHeartRateZones($entries) : [];
    }

    /**
     * @return array{bytes: string, format: 'fit_zip'}
     */
    public function downloadActivityArchive(ProviderContext $context, string $externalActivityId): array
    {
        return [
            'bytes' => $this->client->downloadActivity($context, $externalActivityId),
            'format' => 'fit_zip',
        ];
    }

    public function fetchDailySummary(ProviderContext $context, string $date): ?DailyHealthMetricData
    {
        $raw = $this->callOptional(
            fn (): ?array => $this->client->dailySummary($context, $date),
        );

        return $raw !== null ? $this->mapper->mapDailySummary($raw, $date) : null;
    }

    /**
     * @return list<DailyHealthMetricData>
     */
    public function fetchDailySummariesV2(ProviderContext $context, string $startDate, string $endDate): array
    {
        $response = $this->callOptional(
            fn (): ?array => $this->client->dailySummaries($context, $startDate, $endDate),
        );

        return $response !== null ? $this->mapper->mapDailySummariesV2($response) : [];
    }

    public function fetchDailyHeartRate(ProviderContext $context, string $date): ?DailyHealthMetricData
    {
        $raw = $this->callOptional(
            fn (): ?array => $this->client->dailyHeartRate($context, $date),
        );

        return $raw !== null ? $this->mapper->mapDailyHeartRate($raw, $date) : null;
    }

    /**
     * @return list<DailyHealthMetricData>
     */
    public function fetchDailyBodyBattery(ProviderContext $context, string $startDate, string $endDate): array
    {
        $entries = $this->callOptional(
            fn (): ?array => $this->client->dailyBodyBattery($context, $startDate, $endDate),
        );

        return $entries !== null ? $this->mapper->mapDailyBodyBattery($entries) : [];
    }

    /**
     * @return list<DailyHealthMetricData>
     */
    public function fetchDailyHydration(ProviderContext $context, string $startDate, string $endDate): array
    {
        $entries = $this->callOptional(
            fn (): ?array => $this->client->dailyHydration($context, $startDate, $endDate),
        );

        return $entries !== null ? $this->mapper->mapDailyHydration($entries) : [];
    }

    public function fetchSleepForDate(ProviderContext $context, string $date): ?SleepData
    {
        $raw = $this->callOptional(
            fn (): ?array => $this->client->dailySleep($context, $date),
        );

        return $raw !== null ? $this->mapper->mapSleep($raw, $date) : null;
    }

    /**
     * @return list<SleepData>
     */
    public function fetchSleepSummaries(ProviderContext $context, string $startDate, string $endDate): array
    {
        $entries = $this->callOptional(
            fn (): ?array => $this->client->dailySleepSummaries($context, $startDate, $endDate),
        );

        if ($entries === null) {
            return [];
        }

        $results = [];

        foreach ($entries as $item) {
            $entry = PayloadCoercer::asAssocArray($item);

            if ($entry === null) {
                continue;
            }

            $mapped = $this->mapper->mapSleep($entry, fallbackDate: null);

            if ($mapped !== null) {
                $results[] = $mapped;
            }
        }

        return $results;
    }

    public function fetchFitnessAgeForDate(ProviderContext $context, string $date): ?FitnessAgeData
    {
        $raw = $this->callOptional(
            fn (): ?array => $this->client->fitnessAge($context, $date),
        );

        return $raw !== null ? $this->mapper->mapFitnessAge($raw, $date) : null;
    }

    public function fetchActivityDetail(ProviderContext $context, string $externalActivityId): ?NormalizedActivity
    {
        $raw = $this->callOptional(
            fn (): ?array => $this->client->activity($context, $externalActivityId),
        );

        return $raw !== null ? $this->mapper->mapActivity($raw, $context->timezone) : null;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function fetchActivityWorkouts(ProviderContext $context, string $externalActivityId): ?array
    {
        return $this->callOptional(
            fn (): ?array => $this->client->activityWorkouts($context, $externalActivityId),
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function fetchActivityTypes(ProviderContext $context, bool $forceRefresh = false): ?array
    {
        return $this->callOptional(
            fn (): ?array => $this->client->activityTypes($context, $forceRefresh),
        );
    }

    /**
     * @param  array<string, CarbonInterface|array<int, scalar>|scalar|null>  $filters
     * @return list<array<string, mixed>>
     */
    public function searchActivitiesRaw(ProviderContext $context, array $filters = []): array
    {
        $response = $this->callOptional(
            fn (): ?array => $this->client->searchActivities($context, $filters),
        );

        if ($response === null) {
            return [];
        }

        $results = [];

        foreach ($response as $item) {
            $entry = PayloadCoercer::asAssocArray($item);

            if ($entry !== null) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function callOptional(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ProviderException $exception) {
            if ($exception->statusCode === 404 || $exception->statusCode === 204) {
                return null;
            }

            throw $exception;
        }
    }
}
