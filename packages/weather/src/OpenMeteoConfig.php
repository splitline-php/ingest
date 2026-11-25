<?php

declare(strict_types=1);

namespace Splitline\Weather;

final readonly class OpenMeteoConfig
{
    public const string DEFAULT_FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    public const string DEFAULT_HISTORICAL_FORECAST_URL = 'https://historical-forecast-api.open-meteo.com/v1/forecast';

    public const string DEFAULT_ARCHIVE_URL = 'https://archive-api.open-meteo.com/v1/archive';

    public const string COMMERCIAL_FORECAST_URL = 'https://customer-api.open-meteo.com/v1/forecast';

    public const string COMMERCIAL_HISTORICAL_FORECAST_URL = 'https://customer-historical-forecast-api.open-meteo.com/v1/forecast';

    public const string COMMERCIAL_ARCHIVE_URL = 'https://customer-archive-api.open-meteo.com/v1/archive';

    public function __construct(
        public string $forecastUrl = self::DEFAULT_FORECAST_URL,
        public string $historicalForecastUrl = self::DEFAULT_HISTORICAL_FORECAST_URL,
        public string $archiveUrl = self::DEFAULT_ARCHIVE_URL,
        public int $timeout = 15,
        public int $maxRetries = 2,
        public int $maxRequestsPerMinute = 50,
        public ?string $apiKey = null,
    ) {}

    public static function commercial(
        string $apiKey,
        string $forecastUrl = self::COMMERCIAL_FORECAST_URL,
        string $historicalForecastUrl = self::COMMERCIAL_HISTORICAL_FORECAST_URL,
        string $archiveUrl = self::COMMERCIAL_ARCHIVE_URL,
        int $timeout = 15,
        int $maxRetries = 2,
        int $maxRequestsPerMinute = 50,
    ): self {
        return new self(
            forecastUrl: $forecastUrl,
            historicalForecastUrl: $historicalForecastUrl,
            archiveUrl: $archiveUrl,
            timeout: $timeout,
            maxRetries: $maxRetries,
            maxRequestsPerMinute: $maxRequestsPerMinute,
            apiKey: $apiKey,
        );
    }
}
