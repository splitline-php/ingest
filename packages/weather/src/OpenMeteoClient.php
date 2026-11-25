<?php

declare(strict_types=1);

namespace Splitline\Weather;

use Carbon\CarbonImmutable;
use Closure;
use Splitline\Core\Contracts\HttpTransport;
use Splitline\Core\Contracts\RateLimiter;
use Splitline\Core\Contracts\Sleeper;
use Splitline\Core\Exceptions\ProviderException;
use Splitline\Core\Http\HttpRequest;
use Splitline\Core\Http\HttpResponse;
use Splitline\Weather\Exceptions\OpenMeteoException;

/**
 * HTTP client for Open-Meteo Forecast, Historical Forecast, and Archive APIs.
 *
 * @phpstan-type OpenMeteoHourlySeries array{
 *     time: list<int>,
 *     temperature_2m: list<?float>,
 *     relative_humidity_2m: list<?float>,
 *     apparent_temperature: list<?float>,
 *     dew_point_2m: list<?float>,
 *     wind_speed_10m: list<?float>,
 *     wind_gusts_10m: list<?float>,
 *     wind_direction_10m: list<?float>,
 *     weather_code: list<?int>,
 *     precipitation: list<?float>,
 *     cloud_cover: list<?float>,
 *     surface_pressure: list<?float>
 * }
 */
class OpenMeteoClient
{
    public const string RATE_LIMIT_BUCKET = 'open_meteo';

    private const int RATE_LIMIT_MAX_WAIT_SECONDS = 60;

    private const int FORECAST_LOOKBACK_DAYS = 90;

    /**
     * @var list<string>
     */
    public const array HOURLY_VARIABLES = [
        'temperature_2m',
        'relative_humidity_2m',
        'apparent_temperature',
        'dew_point_2m',
        'wind_speed_10m',
        'wind_gusts_10m',
        'wind_direction_10m',
        'weather_code',
        'precipitation',
        'cloud_cover',
        'surface_pressure',
    ];

    /**
     * @var list<int>
     */
    private const array RETRY_BACKOFF_SECONDS = [1, 3, 8, 15];

    /**
     * @var (Closure(float, string): void)|null
     */
    private ?Closure $rateLimitWaitListener = null;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly RateLimiter $rateLimiter,
        private readonly Sleeper $sleeper,
        private readonly OpenMeteoConfig $config = new OpenMeteoConfig,
    ) {}

    /**
     * Register a listener invoked whenever the client pauses for Open-Meteo rate limiting.
     *
     * @param  (callable(float, string): void)|null  $listener
     */
    public function onRateLimitWait(?callable $listener): void
    {
        $this->rateLimitWaitListener = $listener !== null ? $listener(...) : null;
    }

    /**
     * Choose the appropriate Open-Meteo endpoint based on the activity's UTC start date.
     *
     * @return 'forecast'|'historical_forecast'|'archive'
     */
    public function selectEndpoint(
        CarbonImmutable $activityStartUtc,
        ?CarbonImmutable $nowUtc = null,
    ): string {
        $now = ($nowUtc ?? CarbonImmutable::now('UTC'))->utc();
        $start = $activityStartUtc->utc();

        if ($start->greaterThanOrEqualTo($now->subDays(self::FORECAST_LOOKBACK_DAYS)->startOfDay())) {
            return 'forecast';
        }

        $historicalForecastStart = CarbonImmutable::parse('2022-01-01 00:00:00', 'UTC');

        if ($start->greaterThanOrEqualTo($historicalForecastStart)) {
            return 'historical_forecast';
        }

        return 'archive';
    }

    /**
     * Fetch batched hourly weather data for one or more GNSS coordinates across a UTC time window.
     *
     * @param  non-empty-list<array{lat: float, lng: float}>  $coordinates
     * @return array{endpoint: 'forecast'|'historical_forecast'|'archive', locations: list<OpenMeteoHourlySeries>}
     */
    public function fetchHourly(
        array $coordinates,
        CarbonImmutable $windowStartUtc,
        CarbonImmutable $windowEndUtc,
        ?CarbonImmutable $nowUtc = null,
    ): array {
        $endpoint = $this->selectEndpoint($windowStartUtc, $nowUtc);
        $startDate = $windowStartUtc->utc()->subHour()->format('Y-m-d');
        $endDate = $windowEndUtc->utc()->addHour()->format('Y-m-d');

        $locations = $this->requestEndpoint($endpoint, $coordinates, $startDate, $endDate);

        if ($endpoint === 'historical_forecast' && $this->hasMissingCoverage($locations)) {
            $endpoint = 'archive';
            $locations = $this->requestEndpoint($endpoint, $coordinates, $startDate, $endDate);
        }

        return [
            'endpoint' => $endpoint,
            'locations' => $locations,
        ];
    }

    /**
     * Execute a single batched GET request against an Open-Meteo endpoint with retries and rate limiting.
     *
     * @param  'forecast'|'historical_forecast'|'archive'  $endpoint
     * @param  non-empty-list<array{lat: float, lng: float}>  $coordinates
     * @return list<OpenMeteoHourlySeries>
     */
    private function requestEndpoint(
        string $endpoint,
        array $coordinates,
        string $startDate,
        string $endDate,
    ): array {
        $url = $this->endpointUrl($endpoint);
        $timeout = max(1, $this->config->timeout);
        $maxRetries = max(0, $this->config->maxRetries);

        $query = [
            'latitude' => implode(',', array_map(
                static fn (array $coord): string => (string) round($coord['lat'], 5),
                $coordinates,
            )),
            'longitude' => implode(',', array_map(
                static fn (array $coord): string => (string) round($coord['lng'], 5),
                $coordinates,
            )),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'hourly' => implode(',', self::HOURLY_VARIABLES),
            'temperature_unit' => 'celsius',
            'wind_speed_unit' => 'kmh',
            'precipitation_unit' => 'mm',
            'timezone' => 'UTC',
            'timeformat' => 'unixtime',
        ];

        if ($this->config->apiKey !== null && $this->config->apiKey !== '') {
            $query['apikey'] = $this->config->apiKey;
        }

        $attempt = 0;

        while (true) {
            $this->rateLimiter->acquire(
                self::RATE_LIMIT_BUCKET,
                $this->config->maxRequestsPerMinute,
                self::RATE_LIMIT_MAX_WAIT_SECONDS,
                $this->rateLimitWaitListener,
            );

            $request = new HttpRequest(
                method: 'GET',
                url: $url,
                headers: ['Accept' => 'application/json'],
                query: $query,
                timeoutSeconds: $timeout,
                connectTimeoutSeconds: 5,
            );

            try {
                $response = $this->http->send($request);
            } catch (ProviderException $exception) {
                if ($attempt < $maxRetries) {
                    $waitSeconds = $this->backoffSeconds($attempt);
                    $attempt++;
                    $this->sleepSeconds($waitSeconds);

                    continue;
                }

                throw OpenMeteoException::connectionError($url, $exception);
            }

            if ($response->status === 429) {
                $retryAfterSeconds = $this->resolveRetryAfterSeconds($response, $attempt);

                if ($attempt < $maxRetries) {
                    $attempt++;
                    $this->notifyRateLimitWait(
                        (float) $retryAfterSeconds,
                        "HTTP 429 from Open-Meteo (retry {$attempt}/{$maxRetries})",
                    );
                    $this->sleepSeconds((float) $retryAfterSeconds);

                    continue;
                }

                throw OpenMeteoException::httpError(
                    url: $url,
                    statusCode: 429,
                    responseBody: $response->body,
                    retryAfterSeconds: $retryAfterSeconds,
                );
            }

            if ($response->serverError()) {
                if ($attempt < $maxRetries) {
                    $waitSeconds = $this->backoffSeconds($attempt);
                    $attempt++;
                    $this->sleepSeconds($waitSeconds);

                    continue;
                }

                throw OpenMeteoException::httpError(
                    url: $url,
                    statusCode: $response->status,
                    responseBody: $response->body,
                );
            }

            if (! $response->successful()) {
                throw OpenMeteoException::httpError(
                    url: $url,
                    statusCode: $response->status,
                    responseBody: $response->body,
                );
            }

            return $this->parseResponseLocations($response, $url, count($coordinates));
        }
    }

    /**
     * Normalize Open-Meteo's single-object (1 coordinate) and JSON-array (2+ coordinates) response shapes.
     *
     * @return list<OpenMeteoHourlySeries>
     */
    private function parseResponseLocations(HttpResponse $response, string $url, int $expectedCount): array
    {
        $decoded = $response->json();

        if (! is_array($decoded) || $decoded === []) {
            throw OpenMeteoException::invalidResponse($url, 'Empty or non-JSON payload.');
        }

        $rawLocations = array_is_list($decoded) ? $decoded : [$decoded];

        if (count($rawLocations) !== $expectedCount) {
            throw OpenMeteoException::invalidResponse(
                $url,
                sprintf('Expected %d location payload(s), received %d.', $expectedCount, count($rawLocations)),
            );
        }

        $locations = [];

        foreach ($rawLocations as $index => $entry) {
            if (! is_array($entry) || ! isset($entry['hourly']) || ! is_array($entry['hourly'])) {
                throw OpenMeteoException::invalidResponse($url, "Location #{$index} is missing the hourly object.");
            }

            $hourly = $entry['hourly'];

            if (! isset($hourly['time']) || ! is_array($hourly['time'])) {
                throw OpenMeteoException::invalidResponse($url, "Location #{$index} is missing hourly.time.");
            }

            $times = [];

            foreach ($hourly['time'] as $ts) {
                if (! is_numeric($ts)) {
                    throw OpenMeteoException::invalidResponse($url, "Location #{$index} contains a non-numeric timestamp.");
                }

                $times[] = (int) $ts;
            }

            $length = count($times);

            $locations[] = [
                'time' => $times,
                'temperature_2m' => $this->normalizeFloatSeries($hourly['temperature_2m'] ?? null, $length),
                'relative_humidity_2m' => $this->normalizeFloatSeries($hourly['relative_humidity_2m'] ?? null, $length),
                'apparent_temperature' => $this->normalizeFloatSeries($hourly['apparent_temperature'] ?? null, $length),
                'dew_point_2m' => $this->normalizeFloatSeries($hourly['dew_point_2m'] ?? null, $length),
                'wind_speed_10m' => $this->normalizeFloatSeries($hourly['wind_speed_10m'] ?? null, $length),
                'wind_gusts_10m' => $this->normalizeFloatSeries($hourly['wind_gusts_10m'] ?? null, $length),
                'wind_direction_10m' => $this->normalizeFloatSeries($hourly['wind_direction_10m'] ?? null, $length),
                'weather_code' => $this->normalizeIntSeries($hourly['weather_code'] ?? null, $length),
                'precipitation' => $this->normalizeFloatSeries($hourly['precipitation'] ?? null, $length),
                'cloud_cover' => $this->normalizeFloatSeries($hourly['cloud_cover'] ?? null, $length),
                'surface_pressure' => $this->normalizeFloatSeries($hourly['surface_pressure'] ?? null, $length),
            ];
        }

        return $locations;
    }

    /**
     * Determine whether all returned locations lack temperature or wind speed coverage.
     *
     * @param  list<OpenMeteoHourlySeries>  $locations
     */
    private function hasMissingCoverage(array $locations): bool
    {
        $hasTemperature = false;
        $hasWind = false;

        foreach ($locations as $location) {
            foreach ($location['temperature_2m'] as $value) {
                if ($value !== null) {
                    $hasTemperature = true;
                    break;
                }
            }

            foreach ($location['wind_speed_10m'] as $value) {
                if ($value !== null) {
                    $hasWind = true;
                    break;
                }
            }
        }

        return ! $hasTemperature || ! $hasWind;
    }

    /**
     * @return list<?float>
     */
    private function normalizeFloatSeries(mixed $raw, int $length): array
    {
        $values = [];

        for ($i = 0; $i < $length; $i++) {
            $item = is_array($raw) && array_key_exists($i, $raw) ? $raw[$i] : null;
            $values[] = is_numeric($item) ? (float) $item : null;
        }

        return $values;
    }

    /**
     * @return list<?int>
     */
    private function normalizeIntSeries(mixed $raw, int $length): array
    {
        $values = [];

        for ($i = 0; $i < $length; $i++) {
            $item = is_array($raw) && array_key_exists($i, $raw) ? $raw[$i] : null;
            $values[] = is_numeric($item) ? (int) round((float) $item) : null;
        }

        return $values;
    }

    /**
     * @param  'forecast'|'historical_forecast'|'archive'  $endpoint
     */
    private function endpointUrl(string $endpoint): string
    {
        return match ($endpoint) {
            'forecast' => $this->config->forecastUrl,
            'historical_forecast' => $this->config->historicalForecastUrl,
            'archive' => $this->config->archiveUrl,
        };
    }

    private function resolveRetryAfterSeconds(HttpResponse $response, int $attempt): int
    {
        $retryAfter = trim($response->header('Retry-After'));

        if ($retryAfter !== '') {
            if (is_numeric($retryAfter) && (float) $retryAfter > 0.0) {
                return max(1, (int) ceil((float) $retryAfter));
            }

            $retryTimestamp = strtotime($retryAfter);

            if ($retryTimestamp !== false) {
                $delta = (float) $retryTimestamp - $this->nowSeconds();

                if ($delta > 0.0) {
                    return max(1, (int) ceil($delta));
                }
            }
        }

        return (int) ceil($this->backoffSeconds($attempt));
    }

    private function backoffSeconds(int $attempt): float
    {
        $index = min(max(0, $attempt), count(self::RETRY_BACKOFF_SECONDS) - 1);

        return (float) self::RETRY_BACKOFF_SECONDS[$index];
    }

    private function notifyRateLimitWait(float $waitSeconds, string $reason): void
    {
        if ($this->rateLimitWaitListener !== null) {
            ($this->rateLimitWaitListener)($waitSeconds, $reason);
        }
    }

    private function sleepSeconds(float $seconds): void
    {
        $milliseconds = (int) ceil($seconds * 1000.0);

        if ($milliseconds > 0) {
            $this->sleeper->sleepMilliseconds($milliseconds);
        }
    }

    private function nowSeconds(): float
    {
        return CarbonImmutable::now('UTC')->getPreciseTimestamp(3) / 1000.0;
    }
}
