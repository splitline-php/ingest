<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Splitline\Core\Data\ProviderContext;
use Splitline\Core\Exceptions\ArchiveUnavailableException;
use Splitline\Core\Exceptions\AuthenticationException;
use Splitline\Core\Exceptions\ProviderException;
use Splitline\Core\Exceptions\RateLimitedException;
use Splitline\Core\Http\HttpRequest;
use Splitline\Core\Http\HttpResponse;
use Splitline\Core\Http\SlidingWindowRateLimiter;
use Splitline\ProviderGarminPersonal\GarminPersonalClient;
use Splitline\ProviderGarminPersonal\GarminPersonalConfig;
use Splitline\ProviderGarminPersonal\GarminPersonalTokenManager;
use Splitline\Tests\Support\FakeHttpTransport;
use Splitline\Tests\Support\FakeSleeper;
use Splitline\Tests\Support\InMemoryCacheStore;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function garminPersonalFakeJwt(int $expiresAt, string $signature = 'sig'): string
{
    $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['exp' => $expiresAt], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return "{$header}.{$payload}.{$signature}";
}

/**
 * @return array{
 *     client: GarminPersonalClient,
 *     http: FakeHttpTransport,
 *     cache: InMemoryCacheStore,
 *     sleeper: FakeSleeper,
 *     tokenManager: GarminPersonalTokenManager,
 *     rateLimiter: SlidingWindowRateLimiter
 * }
 */
function makeGarminPersonalClient(
    ?FakeHttpTransport $http = null,
    ?GarminPersonalConfig $config = null,
    bool $advanceClockOnSleep = true,
): array {
    $http ??= new FakeHttpTransport;
    $config ??= new GarminPersonalConfig;
    $cache = new InMemoryCacheStore;
    $sleeper = new FakeSleeper($advanceClockOnSleep
        ? static function (int $ms): void {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds($ms));
        }
        : null);
    $rateLimiter = new SlidingWindowRateLimiter($cache, $sleeper);
    $tokenManager = new GarminPersonalTokenManager($http, $cache, $config);
    $client = new GarminPersonalClient($tokenManager, $http, $cache, $rateLimiter, $sleeper, $config);

    return [
        'client' => $client,
        'http' => $http,
        'cache' => $cache,
        'sleeper' => $sleeper,
        'tokenManager' => $tokenManager,
        'rateLimiter' => $rateLimiter,
    ];
}

function makeGarminContext(
    string $scopeKey = 'default',
    ?string $apiKey = 'test-garmin-key',
    string $timezone = 'Pacific/Auckland',
    ?Closure $onRateLimitWait = null,
): ProviderContext {
    $credentials = $apiKey !== null ? ['api_key' => $apiKey] : [];

    return new ProviderContext(
        scopeKey: $scopeKey,
        timezone: $timezone,
        credentials: $credentials,
        onRateLimitWait: $onRateLimitWait,
    );
}

it('exchanges the personal api key for a bearer token and caches it across requests', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $http = new FakeHttpTransport(responder: static function (HttpRequest $request) use ($jwt): HttpResponse {
        return match ($request->url) {
            'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => new HttpResponse(200, $jwt),
            'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings' => new HttpResponse(200, json_encode([
                'measurementSystem' => 'metric',
                'timeZone' => 'Pacific/Auckland',
            ], JSON_THROW_ON_ERROR)),
            'https://personal.connectapi.garmin.com/usersummary-service/stats/steps/daily/2026-11-01/2026-11-07' => new HttpResponse(200, json_encode([
                ['calendarDate' => '2026-11-01', 'totalSteps' => 9123],
            ], JSON_THROW_ON_ERROR)),
            default => new HttpResponse(404, ''),
        };
    });

    ['client' => $client] = makeGarminPersonalClient($http);
    $context = makeGarminContext();

    $profile = $client->userProfileSettings($context);
    $steps = $client->dailySteps($context, CarbonImmutable::parse('2026-11-01'), '2026-11-07');

    expect($profile)->toBe([
        'measurementSystem' => 'metric',
        'timeZone' => 'Pacific/Auckland',
    ])
        ->and($steps)->toBe([
            ['calendarDate' => '2026-11-01', 'totalSteps' => 9123],
        ]);

    $http->assertSentCount(3);

    expect($http->requests[0]->method)->toBe('POST')
        ->and($http->requests[0]->url)->toBe('https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token')
        ->and($http->requests[0]->headers['Authorization'])->toBe('ApiKey test-garmin-key')
        ->and($http->requests[1]->method)->toBe('GET')
        ->and($http->requests[1]->url)->toBe('https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings')
        ->and($http->requests[1]->headers['Authorization'])->toBe("Bearer {$jwt}")
        ->and($http->requests[2]->headers['Authorization'])->toBe("Bearer {$jwt}");
});

it('refreshes the cached bearer token when within one hour of expiration', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $firstJwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(2)->getTimestamp(), 'first');
    $secondJwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(26)->getTimestamp(), 'second');

    $tokenRequests = 0;
    $http = new FakeHttpTransport(responder: static function (HttpRequest $request) use ($firstJwt, $secondJwt, &$tokenRequests): HttpResponse {
        if (str_contains($request->url, '/personalapitoken-service/')) {
            $tokenRequests++;

            return new HttpResponse(200, $tokenRequests === 1 ? $firstJwt : $secondJwt);
        }

        return new HttpResponse(200, json_encode(['ok' => true], JSON_THROW_ON_ERROR));
    });

    ['client' => $client] = makeGarminPersonalClient($http);
    $context = makeGarminContext();

    $client->userProfileSettings($context);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(61));

    $client->userProfileSettings($context);

    $profileRequests = array_values(array_filter(
        $http->requests,
        static fn (HttpRequest $request): bool => $request->url === 'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings',
    ));

    expect($profileRequests)->toHaveCount(2)
        ->and($profileRequests[0]->headers['Authorization'])->toBe("Bearer {$firstJwt}")
        ->and($profileRequests[1]->headers['Authorization'])->toBe("Bearer {$secondJwt}");
});

it('retries once with a fresh bearer token when garmin responds with 401', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $revokedJwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp(), 'revoked');
    $freshJwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp(), 'fresh');

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, $revokedJwt),
        new HttpResponse(401, json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR)),
        new HttpResponse(200, $freshJwt),
        new HttpResponse(200, json_encode(['measurementSystem' => 'metric'], JSON_THROW_ON_ERROR)),
    );

    ['client' => $client] = makeGarminPersonalClient($http);
    $profile = $client->userProfileSettings(makeGarminContext());

    expect($profile)->toBe(['measurementSystem' => 'metric']);

    $http->assertSentCount(4);
    expect($http->requests[1]->headers['Authorization'])->toBe("Bearer {$revokedJwt}")
        ->and($http->requests[3]->headers['Authorization'])->toBe("Bearer {$freshJwt}");
});

it('throws AuthenticationException when garmin still responds with 401 after a forced token refresh', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $revokedJwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp(), 'revoked');
    $stillInvalidJwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp(), 'still-invalid');

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, $revokedJwt),
        new HttpResponse(401, 'Unauthorized'),
        new HttpResponse(200, $stillInvalidJwt),
        new HttpResponse(401, 'Still unauthorized'),
    );

    ['client' => $client] = makeGarminPersonalClient($http);

    expect(fn (): ?array => $client->userProfileSettings(makeGarminContext()))
        ->toThrow(AuthenticationException::class, 'HTTP 401');

    $http->assertSentCount(4);
});

it('serializes boolean and repeated array query parameters per the swagger spec', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $http = new FakeHttpTransport(responder: static function (HttpRequest $request) use ($jwt): HttpResponse {
        return match ($request->url) {
            'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => new HttpResponse(200, $jwt),
            'https://personal.connectapi.garmin.com/metrics-service/metrics/maxmet/daily/2026-11-01/2026-11-10?sport=RUNNING&sport=CYCLING' => new HttpResponse(200, json_encode([
                ['sport' => 'RUNNING', 'vo2MaxValue' => 54],
            ], JSON_THROW_ON_ERROR)),
            'https://personal.connectapi.garmin.com/weight-service/weight/range/2026-11-01/2026-11-10?includeAll=true' => new HttpResponse(200, json_encode([
                'dailyWeightSummaries' => [],
            ], JSON_THROW_ON_ERROR)),
            default => new HttpResponse(404, "Unexpected URL: {$request->url}"),
        };
    });

    ['client' => $client] = makeGarminPersonalClient($http);
    $context = makeGarminContext();

    expect($client->dailyVo2Max($context, '2026-11-01', '2026-11-10', ['RUNNING', 'CYCLING']))
        ->toBe([['sport' => 'RUNNING', 'vo2MaxValue' => 54]])
        ->and($client->weightMeasurements($context, '2026-11-01', '2026-11-10', includeAll: true))
        ->toBe(['dailyWeightSummaries' => []])
        ->and($http->requests[1]->query)->toBe([])
        ->and($http->requests[2]->query)->toBe([]);
});

it('caches activity types for 48 hours unless forced to refresh and scopes cache keys per context', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $activityTypeCalls = 0;
    $http = new FakeHttpTransport(responder: static function (HttpRequest $request) use ($jwt, &$activityTypeCalls): HttpResponse {
        if (str_contains($request->url, '/personalapitoken-service/')) {
            return new HttpResponse(200, $jwt);
        }

        $activityTypeCalls++;

        return match ($activityTypeCalls) {
            1 => new HttpResponse(200, json_encode([['typeKey' => 'running']], JSON_THROW_ON_ERROR)),
            2 => new HttpResponse(200, json_encode([['typeKey' => 'running'], ['typeKey' => 'cycling']], JSON_THROW_ON_ERROR)),
            default => new HttpResponse(200, json_encode([['typeKey' => 'swimming']], JSON_THROW_ON_ERROR)),
        };
    });

    ['client' => $client, 'cache' => $cache] = makeGarminPersonalClient($http);
    $defaultContext = makeGarminContext(scopeKey: 'default');
    $otherContext = makeGarminContext(scopeKey: 'user_42');

    expect($client->activityTypes($defaultContext))->toBe([['typeKey' => 'running']])
        ->and($client->activityTypes($defaultContext))->toBe([['typeKey' => 'running']])
        ->and($cache->has('garmin_personal:default.activity_types'))->toBeTrue()
        ->and($client->activityTypes($defaultContext, forceRefresh: true))->toBe([['typeKey' => 'running'], ['typeKey' => 'cycling']]);

    $http->assertSentCount(3);

    // Separate scopeKey does not share the cached activity types of 'default'
    expect($client->activityTypes($otherContext))->toBe([['typeKey' => 'swimming']])
        ->and($cache->has('garmin_personal:user_42.activity_types'))->toBeTrue();
});

it('downloads raw zip bytes for valid activity ids and rejects invalid ids', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $http = new FakeHttpTransport(responder: static function (HttpRequest $request) use ($jwt): HttpResponse {
        if (str_contains($request->url, '/personalapitoken-service/')) {
            return new HttpResponse(200, $jwt);
        }

        if ($request->url === 'https://personal.connectapi.garmin.com/download-service/files/activity/123456') {
            return new HttpResponse(200, "PK\x03\x04zipdata", ['Content-Type' => ['application/zip']]);
        }

        return new HttpResponse(404, '');
    });

    ['client' => $client] = makeGarminPersonalClient($http);
    $context = makeGarminContext();

    expect($client->downloadActivity($context, 123456))->toBe("PK\x03\x04zipdata");

    expect(fn (): string => $client->downloadActivity($context, 0))
        ->toThrow(ProviderException::class, 'activityId must be a positive integer.');
});

it('maps HTTP 404 and 204 on downloadActivity to ArchiveUnavailableException', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, $jwt),
        new HttpResponse(404, 'Not found'),
        new HttpResponse(204, ''),
    );

    ['client' => $client] = makeGarminPersonalClient($http);
    $context = makeGarminContext();

    expect(fn (): string => $client->downloadActivity($context, 555))
        ->toThrow(ArchiveUnavailableException::class, 'HTTP 404');

    expect(fn (): string => $client->downloadActivity($context, 556))
        ->toThrow(ArchiveUnavailableException::class, 'HTTP 204');
});

it('throws when GARMIN_KEY is missing', function (): void {
    ['client' => $client] = makeGarminPersonalClient();

    expect(fn (): ?array => $client->userProfileSettings(makeGarminContext(apiKey: '')))
        ->toThrow(AuthenticationException::class, 'GARMIN_KEY is not configured.');
});

it('throws when the token endpoint returns a non-jwt payload', function (): void {
    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, 'not-a-jwt'),
    );

    ['client' => $client] = makeGarminPersonalClient($http);

    expect(fn (): ?array => $client->userProfileSettings(makeGarminContext()))
        ->toThrow(AuthenticationException::class, 'expected a JWT payload');
});

it('retries HTTP 429 responses using garmin retryInSeconds plus a safety buffer', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, $jwt),
        new HttpResponse(429, json_encode([
            'count' => 103,
            'limit' => 100,
            'retryInSeconds' => 7,
            'rateLimitMetaData' => 2,
        ], JSON_THROW_ON_ERROR)),
        new HttpResponse(200, json_encode([
            'calendarDate' => '2026-08-13',
            'totalSteps' => 8420,
        ], JSON_THROW_ON_ERROR)),
    );

    ['client' => $client, 'sleeper' => $sleeper] = makeGarminPersonalClient(
        $http,
        new GarminPersonalConfig(maxRequestsPerMinute: 85, maxRateLimitRetries: 3),
    );

    $waitEvents = [];
    $context = makeGarminContext(onRateLimitWait: static function (float $seconds, string $reason) use (&$waitEvents): void {
        $waitEvents[] = ['seconds' => $seconds, 'reason' => $reason];
    });

    $summary = $client->dailySummary($context, '2026-08-13');

    expect($summary)->toBe(['calendarDate' => '2026-08-13', 'totalSteps' => 8420])
        ->and($waitEvents)->toHaveCount(1)
        ->and($waitEvents[0]['seconds'])->toBe(8.0)
        ->and($waitEvents[0]['reason'])->toContain('HTTP 429')
        ->and($sleeper->sleepsMs)->toBe([8000]);

    $http->assertSentCount(3);
});

it('retries HTTP 429 using Retry-After header and falls back to exponential backoff when no hint is provided', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, $jwt),
        new HttpResponse(429, 'Rate limited', ['Retry-After' => ['4']]),
        new HttpResponse(429, 'Still rate limited'),
        new HttpResponse(200, json_encode(['measurementSystem' => 'metric'], JSON_THROW_ON_ERROR)),
    );

    ['client' => $client, 'sleeper' => $sleeper] = makeGarminPersonalClient(
        $http,
        new GarminPersonalConfig(maxRequestsPerMinute: 85, maxRateLimitRetries: 3),
    );

    $profile = $client->userProfileSettings(makeGarminContext());

    expect($profile)->toBe(['measurementSystem' => 'metric'])
        ->and($sleeper->sleepsMs)->toBe([5000, 5000]);
});

it('throws a rate-limited RateLimitedException with retryAfterSeconds after exhausting 429 retries', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());
    $rateLimitedBody = json_encode([
        'count' => 101,
        'limit' => 100,
        'retryInSeconds' => 6,
    ], JSON_THROW_ON_ERROR);

    $http = new FakeHttpTransport(responder: static function (HttpRequest $request) use ($jwt, $rateLimitedBody): HttpResponse {
        if (str_contains($request->url, '/personalapitoken-service/')) {
            return new HttpResponse(200, $jwt);
        }

        return new HttpResponse(429, $rateLimitedBody);
    });

    ['client' => $client, 'sleeper' => $sleeper] = makeGarminPersonalClient(
        $http,
        new GarminPersonalConfig(maxRequestsPerMinute: 85, maxRateLimitRetries: 2),
    );

    $caught = null;

    try {
        $client->userProfileSettings(makeGarminContext());
    } catch (RateLimitedException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(RateLimitedException::class)
        ->and($caught?->statusCode)->toBe(429)
        ->and($caught?->retryAfterSeconds)->toBe(7)
        ->and($sleeper->sleepsMs)->toHaveCount(2);

    $http->assertSentCount(4); // 1 token + 1 initial + 2 retries
});

it('proactively pauses when the per-minute request budget is reached', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $http = new FakeHttpTransport(responder: static function (HttpRequest $request) use ($jwt): HttpResponse {
        if (str_contains($request->url, '/personalapitoken-service/')) {
            return new HttpResponse(200, $jwt);
        }

        return new HttpResponse(200, json_encode(['ok' => true], JSON_THROW_ON_ERROR));
    });

    ['client' => $client, 'sleeper' => $sleeper] = makeGarminPersonalClient(
        $http,
        new GarminPersonalConfig(maxRequestsPerMinute: 2),
    );

    $waitEvents = [];
    $context = makeGarminContext(onRateLimitWait: static function (float $seconds, string $reason) use (&$waitEvents): void {
        $waitEvents[] = ['seconds' => $seconds, 'reason' => $reason];
    });

    $client->userProfileSettings($context);
    $client->userProfileSettings($context);
    $client->userProfileSettings($context);

    expect($waitEvents)->toHaveCount(1)
        ->and($waitEvents[0]['reason'])->toContain('2/min')
        ->and($sleeper->sleepsMs)->toBe([60250]);
});

it('respects a shared rate-limit cooldown stored in cache before sending requests', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $http = new FakeHttpTransport(responder: static function (HttpRequest $request) use ($jwt): HttpResponse {
        if (str_contains($request->url, '/personalapitoken-service/')) {
            return new HttpResponse(200, $jwt);
        }

        return new HttpResponse(200, json_encode(['ok' => true], JSON_THROW_ON_ERROR));
    });

    ['client' => $client, 'cache' => $cache, 'sleeper' => $sleeper] = makeGarminPersonalClient($http);

    $cooldownKey = SlidingWindowRateLimiter::cooldownUntilCacheKey('garmin_personal:default');
    expect($cooldownKey)->toBe('splitline.rate_limit.garmin_personal:default.cooldown_until');

    $cache->put(
        $cooldownKey,
        (CarbonImmutable::now()->getPreciseTimestamp(3) / 1000.0) + 10.0,
        60,
    );

    expect($client->userProfileSettings(makeGarminContext()))->toBe(['ok' => true])
        ->and($sleeper->sleepsMs)->toBe([10000]);
});

it('retries transient HTTP 5xx responses and connection errors with 200ms and 1000ms delays', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, $jwt),
        new HttpResponse(503, 'Service unavailable'),
        new HttpResponse(502, 'Bad gateway'),
        new HttpResponse(200, json_encode(['ok' => true], JSON_THROW_ON_ERROR)),
        ProviderException::connectionError('GET', 'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings', 'Connection reset'),
        new HttpResponse(200, json_encode(['recovered' => true], JSON_THROW_ON_ERROR)),
    );

    ['client' => $client, 'sleeper' => $sleeper] = makeGarminPersonalClient($http);
    $context = makeGarminContext();

    expect($client->userProfileSettings($context))->toBe(['ok' => true])
        ->and($client->userProfileSettings($context))->toBe(['recovered' => true])
        ->and($sleeper->sleepsMs)->toBe([200, 1000, 200]);

    $http->assertSentCount(6);
});

it('falls back to config defaultApiKey when context credentials omit api_key and returns null on HTTP 204', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $jwt = garminPersonalFakeJwt(CarbonImmutable::now()->addHours(24)->getTimestamp());

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, $jwt),
        new HttpResponse(204, ''),
    );

    ['client' => $client] = makeGarminPersonalClient(
        $http,
        new GarminPersonalConfig(defaultApiKey: 'fallback-config-key'),
    );

    $context = makeGarminContext(apiKey: null);

    expect($client->dailySummary($context, '2026-11-21'))->toBeNull()
        ->and($http->requests[0]->headers['Authorization'])->toBe('ApiKey fallback-config-key');
});
