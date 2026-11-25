<?php

declare(strict_types=1);

namespace Splitline\ProviderGarminPersonal;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Splitline\Core\Contracts\CacheStore;
use Splitline\Core\Contracts\CredentialProvider;
use Splitline\Core\Contracts\HttpTransport;
use Splitline\Core\Contracts\RateLimiter;
use Splitline\Core\Contracts\Sleeper;
use Splitline\Core\Data\ProviderContext;
use Splitline\Core\Exceptions\ArchiveUnavailableException;
use Splitline\Core\Exceptions\AuthenticationException;
use Splitline\Core\Exceptions\ProviderException;
use Splitline\Core\Exceptions\RateLimitedException;
use Splitline\Core\Http\HttpRequest;
use Splitline\Core\Http\HttpResponse;

class GarminPersonalClient
{
    public const int ACTIVITY_TYPES_CACHE_TTL_SECONDS = 172800;

    private const float RATE_LIMIT_SAFETY_BUFFER_SECONDS = 1.0;

    /**
     * @var list<int>
     */
    private const array TRANSIENT_RETRY_DELAYS_MS = [200, 1000];

    /**
     * @var list<int>
     */
    private const array RATE_LIMIT_FALLBACK_BACKOFF_SECONDS = [2, 5, 15, 30, 60];

    public function __construct(
        private readonly CredentialProvider $tokenManager,
        private readonly HttpTransport $http,
        private readonly CacheStore $cache,
        private readonly RateLimiter $rateLimiter,
        private readonly Sleeper $sleeper,
        private readonly GarminPersonalConfig $config = new GarminPersonalConfig,
    ) {}

    public static function rateLimitBucketKey(string $scopeKey): string
    {
        return "garmin_personal:{$scopeKey}";
    }

    public static function activityTypesCacheKey(string $scopeKey): string
    {
        return "garmin_personal:{$scopeKey}.activity_types";
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userProfileSettings(ProviderContext $context): ?array
    {
        return $this->getJsonObject($context, '/userprofile-service/userprofile/settings');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailySummary(ProviderContext $context, CarbonInterface|string|null $date = null): ?array
    {
        return $this->getJsonObject($context, '/usersummary-service/usersummary/daily', [
            'calendarDate' => $date !== null ? $this->formatDate($date) : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailySummaries(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ?array {
        return $this->getJsonObject($context, '/usersummary-service/v2/dailysummaries', [
            'startDate' => $this->formatDate($startDate),
            'endDate' => $this->formatDate($endDate),
        ]);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailySteps(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ?array {
        return $this->getJsonArray(
            $context,
            sprintf('/usersummary-service/stats/steps/daily/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailyStress(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ?array {
        return $this->getJsonArray(
            $context,
            sprintf('/usersummary-service/stats/stress/daily/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailyHydration(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ?array {
        return $this->getJsonArray(
            $context,
            sprintf('/usersummary-service/stats/hydration/daily/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailySleep(ProviderContext $context, CarbonInterface|string|null $date = null): ?array
    {
        return $this->getJsonObject($context, '/sleep-service/sleep/dailySleep', [
            'date' => $date !== null ? $this->formatDate($date) : null,
        ]);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailySleepSummaries(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ?array {
        return $this->getJsonArray($context, '/sleep-service/sleep/dailySleepSummariesByDate', [
            'startDate' => $this->formatDate($startDate),
            'endDate' => $this->formatDate($endDate),
        ]);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailyBodyBattery(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?int $chunks = null,
    ): ?array {
        return $this->getJsonArray($context, '/wellness-service/wellness/bodyBattery/reports/daily', [
            'startDate' => $this->formatDate($startDate),
            'endDate' => $this->formatDate($endDate),
            'chunks' => $chunks,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailyHeartRate(ProviderContext $context, CarbonInterface|string|null $date = null): ?array
    {
        return $this->getJsonObject($context, '/wellness-service/wellness/dailyHeartRate', [
            'date' => $date !== null ? $this->formatDate($date) : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailySpo2(ProviderContext $context, CarbonInterface|string $date): ?array
    {
        return $this->getJsonObject(
            $context,
            sprintf('/wellness-service/wellness/daily/spo2acclimation/%s', $this->formatDate($date)),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailyHrv(ProviderContext $context, CarbonInterface|string $date): ?array
    {
        return $this->getJsonObject(
            $context,
            sprintf('/hrv-service/hrv/%s', $this->formatDate($date)),
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailyHrvSummaries(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ?array {
        return $this->getJsonArray(
            $context,
            sprintf('/hrv-service/hrv/daily/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @param  list<string>|null  $sports
     * @return array<int|string, mixed>|null
     */
    public function dailyVo2Max(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?array $sports = null,
    ): ?array {
        return $this->getJsonArray(
            $context,
            sprintf('/metrics-service/metrics/maxmet/daily/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
            ['sport' => $sports],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fitnessAge(
        ProviderContext $context,
        CarbonInterface|string $date,
        ?bool $fullHistoryScan = null,
    ): ?array {
        return $this->getJsonObject(
            $context,
            sprintf('/fitnessage-service/fitnessage/v2/%s', $this->formatDate($date)),
            ['fullHistoryScan' => $fullHistoryScan],
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function heartRateZones(ProviderContext $context, ?bool $generateDefaults = null): ?array
    {
        return $this->getJsonArray($context, '/biometric-service/heartRateZones/v2', [
            'generateDefaults' => $generateDefaults,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function weightMeasurements(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        bool $includeAll = true,
    ): ?array {
        return $this->getJsonObject(
            $context,
            sprintf('/weight-service/weight/range/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
            ['includeAll' => $includeAll],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function allWeightMeasurements(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ?array {
        return $this->getJsonObject(
            $context,
            sprintf('/weight-service/weight/all/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function effectiveWeightGoals(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ?array {
        return $this->getJsonArray(
            $context,
            sprintf('/goal-service/goal/user/effective/weightgoal/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @param  array{includeGoals?: bool, includeNutrition?: bool, includeMedia?: bool, mealId?: int}  $options
     * @return array<string, mixed>|null
     */
    public function dailyFoodLogs(
        ProviderContext $context,
        CarbonInterface|string $date,
        array $options = [],
    ): ?array {
        return $this->getJsonObject(
            $context,
            sprintf('/nutrition-service/food/logs/%s', $this->formatDate($date)),
            $options,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function menstrualCycleSummary(ProviderContext $context, CarbonInterface|string $date): ?array
    {
        return $this->getJsonObject(
            $context,
            sprintf('/periodichealth-service/menstrualcycle/summary/%s', $this->formatDate($date)),
        );
    }

    /**
     * @param  array{reducedResponse?: bool, skipbm?: bool, includeAnalysis?: bool}  $options
     * @return array<string, mixed>|null
     */
    public function menstrualCycleDayView(
        ProviderContext $context,
        CarbonInterface|string $date,
        CarbonInterface|string|null $todayDate = null,
        array $options = [],
    ): ?array {
        return $this->getJsonObject(
            $context,
            sprintf('/periodichealth-service/menstrualcycle/dayview/%s', $this->formatDate($date)),
            [
                'todayDate' => $todayDate !== null ? $this->formatDate($todayDate) : null,
                ...$options,
            ],
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function activityTypes(ProviderContext $context, bool $forceRefresh = false): ?array
    {
        $cacheKey = self::activityTypesCacheKey($context->scopeKey);

        if (! $forceRefresh) {
            $cached = $this->cache->get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $types = $this->getJsonArray($context, '/activity-service/activity/activityTypes/simple');

        if ($types !== null) {
            $this->cache->put(
                $cacheKey,
                $types,
                self::ACTIVITY_TYPES_CACHE_TTL_SECONDS,
            );
        }

        return $types;
    }

    /**
     * @param  array<string, CarbonInterface|array<int, scalar>|scalar|null>  $filters
     * @return array<int|string, mixed>|null
     */
    public function searchActivities(ProviderContext $context, array $filters = []): ?array
    {
        $normalized = [];

        foreach ($filters as $key => $value) {
            $normalized[$key] = $value instanceof CarbonInterface ? $this->formatDate($value) : $value;
        }

        return $this->getJsonArray($context, '/activitylist-service/activities/search/activities', $normalized);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activity(ProviderContext $context, int|string $activityId): ?array
    {
        return $this->getJsonObject($context, sprintf('/activity-service/activity/%s', $activityId));
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function activityWorkouts(ProviderContext $context, int|string $activityId): ?array
    {
        return $this->getJsonArray($context, sprintf('/activity-service/activity/%s/workouts', $activityId));
    }

    /**
     * Download the original activity archive as raw ZIP bytes.
     */
    public function downloadActivity(ProviderContext $context, int|string $activityId): string
    {
        $id = (string) $activityId;

        if (! ctype_digit($id) || (int) $id <= 0) {
            throw new ProviderException('activityId must be a positive integer.');
        }

        $path = "/download-service/files/activity/{$id}";

        try {
            $response = $this->requestGet(
                context: $context,
                path: $path,
                accept: 'application/zip, application/x-zip-compressed, application/octet-stream',
            );
        } catch (ProviderException $exception) {
            if (
                $exception->statusCode === 404
                && $exception->url !== null
                && str_contains($exception->url, $path)
            ) {
                throw ArchiveUnavailableException::forActivity(
                    url: $exception->url,
                    statusCode: 404,
                    responseBody: $exception->responseBody ?? '',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        if ($response->status === 204) {
            throw ArchiveUnavailableException::forActivity(
                url: rtrim($this->config->baseUrl, '/').$path,
                statusCode: 204,
            );
        }

        if ($response->body === '') {
            throw new ProviderException("Garmin returned an empty archive for activity {$id}.");
        }

        return $response->body;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function wellnessActivitySummaries(
        ProviderContext $context,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ?array {
        return $this->getJsonArray(
            $context,
            sprintf('/wellnessactivity-service/activity/summary/range/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @param  array<string, array<int, scalar>|scalar|null>  $query
     */
    public function get(ProviderContext $context, string $path, array $query = []): mixed
    {
        $response = $this->requestGet($context, $path, $query);

        if ($response->status === 204 || trim($response->body) === '') {
            return null;
        }

        return $response->json();
    }

    /**
     * @param  array<string, array<int, scalar>|scalar|null>  $query
     * @return array<int|string, mixed>|null
     */
    private function getJsonArray(ProviderContext $context, string $path, array $query = []): ?array
    {
        $data = $this->get($context, $path, $query);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, array<int, scalar>|scalar|null>  $query
     * @return array<string, mixed>|null
     */
    private function getJsonObject(ProviderContext $context, string $path, array $query = []): ?array
    {
        $data = $this->get($context, $path, $query);

        if (! is_array($data)) {
            return null;
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<int, scalar>|scalar|null>  $query
     */
    private function requestGet(
        ProviderContext $context,
        string $path,
        array $query = [],
        string $accept = 'application/json',
    ): HttpResponse {
        $relativeUrl = $this->buildPathWithQuery($path, $query);
        $fullUrl = rtrim($this->config->baseUrl, '/').'/'.ltrim($relativeUrl, '/');
        $bucketKey = self::rateLimitBucketKey($context->scopeKey);

        $retriedAuth = false;
        $forceTokenRefresh = false;
        $rateLimitAttempt = 0;
        $lastRateLimitWaitSeconds = null;

        while (true) {
            $this->rateLimiter->acquire(
                $bucketKey,
                $this->config->maxRequestsPerMinute,
                $this->config->maxRetryWaitSeconds,
                $context->onRateLimitWait,
            );

            $response = $this->sendGet($context, $fullUrl, $accept, forceRefresh: $forceTokenRefresh);
            $forceTokenRefresh = false;

            if ($response->status === 401 && ! $retriedAuth) {
                $retriedAuth = true;
                $forceTokenRefresh = true;
                $this->tokenManager->forgetToken($context);

                continue;
            }

            if ($response->status === 429) {
                $waitSeconds = $this->resolveRateLimitWaitSeconds($response, $rateLimitAttempt);
                $lastRateLimitWaitSeconds = $waitSeconds;
                $this->rateLimiter->recordCooldown($bucketKey, $waitSeconds);

                if ($rateLimitAttempt >= max(0, $this->config->maxRateLimitRetries)) {
                    break;
                }

                $rateLimitAttempt++;
                $this->notifyRateLimitWait(
                    $context,
                    $waitSeconds,
                    "HTTP 429 from Garmin (retry {$rateLimitAttempt}/{$this->config->maxRateLimitRetries})",
                );
                $this->sleepSeconds($waitSeconds);

                continue;
            }

            break;
        }

        if (! $response->successful()) {
            if ($response->status === 401) {
                $summary = trim($response->body);
                $suffix = $summary !== '' ? ": {$summary}" : '';

                throw new AuthenticationException(
                    message: "Provider HTTP GET {$fullUrl} failed with HTTP 401{$suffix}",
                    method: 'GET',
                    url: $fullUrl,
                    statusCode: 401,
                    responseBody: $response->body,
                );
            }

            if ($response->status === 429) {
                throw RateLimitedException::fromResponse(
                    method: 'GET',
                    url: $fullUrl,
                    statusCode: 429,
                    responseBody: $response->body,
                    retryAfterSeconds: (int) ceil($lastRateLimitWaitSeconds),
                );
            }

            throw ProviderException::httpError(
                method: 'GET',
                url: $fullUrl,
                statusCode: $response->status,
                responseBody: $response->body,
            );
        }

        return $response;
    }

    private function sendGet(
        ProviderContext $context,
        string $fullUrl,
        string $accept,
        bool $forceRefresh,
    ): HttpResponse {
        $token = $this->tokenManager->getAccessToken($context, $forceRefresh);

        $request = new HttpRequest(
            method: 'GET',
            url: $fullUrl,
            headers: [
                'Accept' => $accept,
                'Authorization' => 'Bearer '.$token,
            ],
            timeoutSeconds: $this->config->timeout,
            connectTimeoutSeconds: 5,
        );

        $maxTransientRetries = count(self::TRANSIENT_RETRY_DELAYS_MS);

        for ($attempt = 0; $attempt <= $maxTransientRetries; $attempt++) {
            try {
                $response = $this->http->send($request);
            } catch (ProviderException $exception) {
                if ($attempt < $maxTransientRetries) {
                    $this->sleeper->sleepMilliseconds(self::TRANSIENT_RETRY_DELAYS_MS[$attempt]);

                    continue;
                }

                throw $exception;
            }

            if ($response->serverError() && $attempt < $maxTransientRetries) {
                $this->sleeper->sleepMilliseconds(self::TRANSIENT_RETRY_DELAYS_MS[$attempt]);

                continue;
            }

            return $response;
        }

        throw ProviderException::connectionError('GET', $fullUrl, 'Exhausted transient retries.');
    }

    private function resolveRateLimitWaitSeconds(HttpResponse $response, int $attempt): float
    {
        $decoded = json_decode($response->body, true);

        if (
            is_array($decoded)
            && isset($decoded['retryInSeconds'])
            && is_numeric($decoded['retryInSeconds'])
            && (float) $decoded['retryInSeconds'] > 0.0
        ) {
            return $this->clampRetryWait((float) $decoded['retryInSeconds'] + self::RATE_LIMIT_SAFETY_BUFFER_SECONDS);
        }

        $retryAfter = trim($response->header('Retry-After'));

        if ($retryAfter !== '') {
            if (is_numeric($retryAfter) && (float) $retryAfter > 0.0) {
                return $this->clampRetryWait((float) $retryAfter + self::RATE_LIMIT_SAFETY_BUFFER_SECONDS);
            }

            $retryTimestamp = strtotime($retryAfter);

            if ($retryTimestamp !== false) {
                $delta = (float) $retryTimestamp - $this->nowSeconds();

                if ($delta > 0.0) {
                    return $this->clampRetryWait($delta + self::RATE_LIMIT_SAFETY_BUFFER_SECONDS);
                }
            }
        }

        $fallbackIndex = min(max(0, $attempt), count(self::RATE_LIMIT_FALLBACK_BACKOFF_SECONDS) - 1);

        return $this->clampRetryWait((float) self::RATE_LIMIT_FALLBACK_BACKOFF_SECONDS[$fallbackIndex]);
    }

    private function clampRetryWait(float $seconds): float
    {
        return max(1.0, min($seconds, (float) max(1, $this->config->maxRetryWaitSeconds)));
    }

    private function notifyRateLimitWait(ProviderContext $context, float $waitSeconds, string $reason): void
    {
        if ($context->onRateLimitWait !== null) {
            ($context->onRateLimitWait)($waitSeconds, $reason);
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

    private function formatDate(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->format('Y-m-d') : $date;
    }

    /**
     * @param  array<string, array<int, scalar>|scalar|null>  $query
     */
    private function buildPathWithQuery(string $path, array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = rawurlencode($key).'='.rawurlencode($this->stringifyQueryValue($item));
                }

                continue;
            }

            $pairs[] = rawurlencode($key).'='.rawurlencode($this->stringifyQueryValue($value));
        }

        if ($pairs === []) {
            return $path;
        }

        return $path.'?'.implode('&', $pairs);
    }

    private function stringifyQueryValue(bool|float|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
