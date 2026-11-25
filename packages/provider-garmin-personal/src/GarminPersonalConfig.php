<?php

declare(strict_types=1);

namespace Splitline\ProviderGarminPersonal;

final readonly class GarminPersonalConfig
{
    public const string DEFAULT_BASE_URL = 'https://personal.connectapi.garmin.com';

    public function __construct(
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public int $timeout = 30,
        public int $maxRequestsPerMinute = 85,
        public int $maxRateLimitRetries = 5,
        public int $maxRetryWaitSeconds = 120,
        public ?string $defaultApiKey = null,
    ) {}
}
