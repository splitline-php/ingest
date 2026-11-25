<?php

declare(strict_types=1);

namespace Splitline\Core\Contracts;

use Splitline\Core\Data\ProviderCapabilities;

interface TelemetryProvider
{
    /**
     * Unique snake_case provider identifier (e.g. 'garmin_personal', 'garmin_health', 'polar', 'oura', 'fit_upload').
     */
    public function id(): string;

    /**
     * Human-readable vendor/provider name (e.g. 'Garmin Connect', 'Polar', 'Oura').
     */
    public function displayName(): string;

    /**
     * Feature flags + compliance flags (including allowsGroupSharing for Strava-style API restrictions).
     */
    public function capabilities(): ProviderCapabilities;
}
