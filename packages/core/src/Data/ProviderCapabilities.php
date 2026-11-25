<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

final readonly class ProviderCapabilities
{
    public function __construct(
        public bool $supportsActivities = false,
        public bool $supportsActivityArchives = false,
        public bool $supportsDailyMetrics = false,
        public bool $supportsSleep = false,
        public bool $supportsHrv = false,
        public bool $supportsVo2Max = false,
        public bool $supportsFitnessAge = false,
        public bool $supportsWeight = false,
        public bool $supportsHeartRateZones = false,
        public bool $supportsWebhooks = false,
        public bool $allowsGroupSharing = true,
        public bool $requiresCommercialLicense = false,
    ) {}

    /**
     * @return array{
     *     supports_activities: bool,
     *     supports_activity_archives: bool,
     *     supports_daily_metrics: bool,
     *     supports_sleep: bool,
     *     supports_hrv: bool,
     *     supports_vo2_max: bool,
     *     supports_fitness_age: bool,
     *     supports_weight: bool,
     *     supports_heart_rate_zones: bool,
     *     supports_webhooks: bool,
     *     allows_group_sharing: bool,
     *     requires_commercial_license: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'supports_activities' => $this->supportsActivities,
            'supports_activity_archives' => $this->supportsActivityArchives,
            'supports_daily_metrics' => $this->supportsDailyMetrics,
            'supports_sleep' => $this->supportsSleep,
            'supports_hrv' => $this->supportsHrv,
            'supports_vo2_max' => $this->supportsVo2Max,
            'supports_fitness_age' => $this->supportsFitnessAge,
            'supports_weight' => $this->supportsWeight,
            'supports_heart_rate_zones' => $this->supportsHeartRateZones,
            'supports_webhooks' => $this->supportsWebhooks,
            'allows_group_sharing' => $this->allowsGroupSharing,
            'requires_commercial_license' => $this->requiresCommercialLicense,
        ];
    }
}
