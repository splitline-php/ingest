<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

final readonly class WebhookParseResult
{
    /**
     * @param  list<NormalizedActivity>  $activities
     * @param  list<DailyHealthMetricData>  $dailyMetrics
     * @param  list<SleepData>  $sleep
     * @param  list<HrvData>  $hrv
     * @param  list<WeightMeasurementData>  $weights
     * @param  list<array{external_activity_id: string, download_url: ?string}>  $pendingArchiveDownloads
     * @param  list<string>  $deregisteredUserIds
     */
    public function __construct(
        public ?string $externalUserId = null,
        public array $activities = [],
        public array $dailyMetrics = [],
        public array $sleep = [],
        public array $hrv = [],
        public array $weights = [],
        public array $pendingArchiveDownloads = [],
        public array $deregisteredUserIds = [],
    ) {}
}
