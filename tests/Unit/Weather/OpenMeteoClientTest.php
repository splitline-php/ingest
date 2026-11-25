<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Splitline\Core\Exceptions\ProviderException;
use Splitline\Core\Http\HttpResponse;
use Splitline\Core\Http\SlidingWindowRateLimiter;
use Splitline\Tests\Support\FakeHttpTransport;
use Splitline\Tests\Support\FakeSleeper;
use Splitline\Tests\Support\InMemoryCacheStore;
use Splitline\Weather\Exceptions\OpenMeteoException;
use Splitline\Weather\OpenMeteoClient;
use Splitline\Weather\OpenMeteoConfig;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<int>  $timestamps
 * @param  array<string, list<float|int|null>>  $overrides
 * @return array{hourly: array<string, list<float|int|null>>}
 */
function makeOpenMeteoPayload(array $timestamps, array $overrides = []): array
{
    $count = count($timestamps);

    $defaults = [
        'time' => $timestamps,
        'temperature_2m' => array_fill(0, $count, 16.0),
        'relative_humidity_2m' => array_fill(0, $count, 70.0),
        'apparent_temperature' => array_fill(0, $count, 14.0),
        'dew_point_2m' => array_fill(0, $count, 10.5),
        'wind_speed_10m' => array_fill(0, $count, 14.0),
        'wind_gusts_10m' => array_fill(0, $count, 22.0),
        'wind_direction_10m' => array_fill(0, $count, 45.0),
        'weather_code' => array_fill(0, $count, 2),
        'precipitation' => array_fill(0, $count, 0.0),
        'cloud_cover' => array_fill(0, $count, 50.0),
        'surface_pressure' => array_fill(0, $count, 1015.0),
    ];

    return [
        'hourly' => array_merge($defaults, $overrides),
    ];
}

it('selects forecast, historical_forecast, and archive endpoints based on UTC activity date', function (): void {
    $now = CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC');
    $http = new FakeHttpTransport;
    $sleeper = new FakeSleeper;
    $limiter = new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper);
    $client = new OpenMeteoClient($http, $limiter, $sleeper);

    expect($client->selectEndpoint(CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'), $now))->toBe('forecast')
        ->and($client->selectEndpoint(CarbonImmutable::parse('2026-08-24 00:00:00', 'UTC'), $now))->toBe('forecast')
        ->and($client->selectEndpoint(CarbonImmutable::parse('2026-08-23 23:59:59', 'UTC'), $now))->toBe('historical_forecast')
        ->and($client->selectEndpoint(CarbonImmutable::parse('2022-01-01 00:00:00', 'UTC'), $now))->toBe('historical_forecast')
        ->and($client->selectEndpoint(CarbonImmutable::parse('2021-12-31 23:59:59', 'UTC'), $now))->toBe('archive')
        ->and($client->selectEndpoint(CarbonImmutable::parse('2019-08-10 08:00:00', 'UTC'), $now))->toBe('archive');
});

it('uses free Open-Meteo endpoints without apikey query parameter by default', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $ts = [
        CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC')->getTimestamp(),
        CarbonImmutable::parse('2026-11-20 07:00:00', 'UTC')->getTimestamp(),
    ];

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts))),
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts))),
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts))),
    );
    $sleeper = new FakeSleeper;
    $limiter = new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper);
    $client = new OpenMeteoClient($http, $limiter, $sleeper, new OpenMeteoConfig);

    $forecast = $client->fetchHourly(
        [['lat' => -36.8485123, 'lng' => 174.7633456]],
        CarbonImmutable::parse('2026-11-20 06:15:00', 'UTC'),
        CarbonImmutable::parse('2026-11-20 06:45:00', 'UTC'),
    );

    $historical = $client->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2023-06-15 08:15:00', 'UTC'),
        CarbonImmutable::parse('2023-06-15 08:45:00', 'UTC'),
    );

    $archive = $client->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2019-08-10 08:15:00', 'UTC'),
        CarbonImmutable::parse('2019-08-10 08:45:00', 'UTC'),
    );

    expect($forecast['endpoint'])->toBe('forecast')
        ->and($historical['endpoint'])->toBe('historical_forecast')
        ->and($archive['endpoint'])->toBe('archive')
        ->and($http->requests)->toHaveCount(3)
        ->and($http->requests[0]->url)->toBe('https://api.open-meteo.com/v1/forecast')
        ->and($http->requests[1]->url)->toBe('https://historical-forecast-api.open-meteo.com/v1/forecast')
        ->and($http->requests[2]->url)->toBe('https://archive-api.open-meteo.com/v1/archive')
        ->and($http->requests[0]->query)->not->toHaveKey('apikey')
        ->and($http->requests[1]->query)->not->toHaveKey('apikey')
        ->and($http->requests[2]->query)->not->toHaveKey('apikey')
        ->and($http->requests[0]->query['latitude'])->toBe('-36.84851')
        ->and($http->requests[0]->query['longitude'])->toBe('174.76335')
        ->and($http->requests[0]->headers)->toBe(['Accept' => 'application/json']);
});

it('uses commercial customer endpoints and appends apikey when configured via OpenMeteoConfig::commercial()', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $ts = [
        CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC')->getTimestamp(),
        CarbonImmutable::parse('2026-11-20 07:00:00', 'UTC')->getTimestamp(),
    ];

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts))),
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts))),
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts))),
    );
    $sleeper = new FakeSleeper;
    $limiter = new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper);
    $config = OpenMeteoConfig::commercial('om-secret-key-123');
    $client = new OpenMeteoClient($http, $limiter, $sleeper, $config);

    $client->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2026-11-20 06:15:00', 'UTC'),
        CarbonImmutable::parse('2026-11-20 06:45:00', 'UTC'),
    );

    $client->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2023-06-15 08:15:00', 'UTC'),
        CarbonImmutable::parse('2023-06-15 08:45:00', 'UTC'),
    );

    $client->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2019-08-10 08:15:00', 'UTC'),
        CarbonImmutable::parse('2019-08-10 08:45:00', 'UTC'),
    );

    expect($http->requests)->toHaveCount(3)
        ->and($http->requests[0]->url)->toBe('https://customer-api.open-meteo.com/v1/forecast')
        ->and($http->requests[1]->url)->toBe('https://customer-historical-forecast-api.open-meteo.com/v1/forecast')
        ->and($http->requests[2]->url)->toBe('https://customer-archive-api.open-meteo.com/v1/archive')
        ->and($http->requests[0]->query['apikey'])->toBe('om-secret-key-123')
        ->and($http->requests[1]->query['apikey'])->toBe('om-secret-key-123')
        ->and($http->requests[2]->query['apikey'])->toBe('om-secret-key-123');
});

it('falls back from historical_forecast to archive when temperature or wind coverage is missing', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $ts = [
        CarbonImmutable::parse('2023-06-15 08:00:00', 'UTC')->getTimestamp(),
        CarbonImmutable::parse('2023-06-15 09:00:00', 'UTC')->getTimestamp(),
    ];

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts, [
            'temperature_2m' => [null, null],
            'wind_speed_10m' => [null, null],
        ]))),
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts, [
            'temperature_2m' => [11.0, 13.0],
        ]))),
    );
    $sleeper = new FakeSleeper;
    $limiter = new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper);
    $client = new OpenMeteoClient($http, $limiter, $sleeper);

    $result = $client->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2023-06-15 08:15:00', 'UTC'),
        CarbonImmutable::parse('2023-06-15 08:45:00', 'UTC'),
    );

    expect($result['endpoint'])->toBe('archive')
        ->and($result['locations'][0]['temperature_2m'])->toBe([11.0, 13.0])
        ->and($http->requests)->toHaveCount(2)
        ->and($http->requests[0]->url)->toBe(OpenMeteoConfig::DEFAULT_HISTORICAL_FORECAST_URL)
        ->and($http->requests[1]->url)->toBe(OpenMeteoConfig::DEFAULT_ARCHIVE_URL);
});

it('retries HTTP 429 honoring Retry-After and fallback backoff while notifying onRateLimitWait', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $ts = [CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC')->getTimestamp()];

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(429, 'Rate limit exceeded', ['Retry-After' => ['7']]),
        new HttpResponse(429, 'Rate limit exceeded'),
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts))),
    );
    $sleeper = new FakeSleeper;
    $limiter = new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper);
    $client = new OpenMeteoClient($http, $limiter, $sleeper, new OpenMeteoConfig(maxRetries: 2));

    $notifications = [];
    $client->onRateLimitWait(static function (float $seconds, string $reason) use (&$notifications): void {
        $notifications[] = ['seconds' => $seconds, 'reason' => $reason];
    });

    $result = $client->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'),
        CarbonImmutable::parse('2026-11-20 06:30:00', 'UTC'),
    );

    expect($result['endpoint'])->toBe('forecast')
        ->and($http->requests)->toHaveCount(3)
        ->and($sleeper->sleepsMs)->toBe([7000, 3000])
        ->and($notifications)->toBe([
            ['seconds' => 7.0, 'reason' => 'HTTP 429 from Open-Meteo (retry 1/2)'],
            ['seconds' => 3.0, 'reason' => 'HTTP 429 from Open-Meteo (retry 2/2)'],
        ]);
});

it('throws rate-limited OpenMeteoException with Retry-After metadata when 429 retries are exhausted', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(429, 'Minutely API request limit exceeded.', ['Retry-After' => ['42']]),
    );
    $sleeper = new FakeSleeper;
    $limiter = new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper);
    $client = new OpenMeteoClient($http, $limiter, $sleeper, new OpenMeteoConfig(maxRetries: 0));

    try {
        $client->fetchHourly(
            [['lat' => -36.8485, 'lng' => 174.7633]],
            CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-20 06:30:00', 'UTC'),
        );

        $this->fail('Expected OpenMeteoException to be thrown.');
    } catch (OpenMeteoException $exception) {
        expect($exception->isRateLimited())->toBeTrue()
            ->and($exception->statusCode)->toBe(429)
            ->and($exception->retryAfterSeconds)->toBe(42)
            ->and($exception->url)->toBe(OpenMeteoConfig::DEFAULT_FORECAST_URL)
            ->and($exception->context())->toBe([
                'status_code' => 429,
                'retry_after_seconds' => 42,
                'url' => OpenMeteoConfig::DEFAULT_FORECAST_URL,
            ]);
    }

    expect($sleeper->sleepsMs)->toBe([]);
});

it('retries HTTP 5xx responses with exponential backoff and throws on exhaustion', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $ts = [CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC')->getTimestamp()];

    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(503, 'Service unavailable'),
        new HttpResponse(502, 'Bad gateway'),
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts))),
    );
    $sleeper = new FakeSleeper;
    $limiter = new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper);
    $client = new OpenMeteoClient($http, $limiter, $sleeper, new OpenMeteoConfig(maxRetries: 2));

    $result = $client->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'),
        CarbonImmutable::parse('2026-11-20 06:30:00', 'UTC'),
    );

    expect($result['endpoint'])->toBe('forecast')
        ->and($http->requests)->toHaveCount(3)
        ->and($sleeper->sleepsMs)->toBe([1000, 3000]);

    // Exhausted 5xx throws OpenMeteoException
    $failingHttp = FakeHttpTransport::fakeSequence(
        new HttpResponse(500, 'Internal error'),
        new HttpResponse(503, 'Still down'),
    );
    $failingSleeper = new FakeSleeper;
    $failingClient = new OpenMeteoClient(
        $failingHttp,
        new SlidingWindowRateLimiter(new InMemoryCacheStore, $failingSleeper),
        $failingSleeper,
        new OpenMeteoConfig(maxRetries: 1),
    );

    expect(fn (): array => $failingClient->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'),
        CarbonImmutable::parse('2026-11-20 06:30:00', 'UTC'),
    ))->toThrow(OpenMeteoException::class, 'HTTP 503');

    expect($failingSleeper->sleepsMs)->toBe([1000]);
});

it('retries ProviderException connection errors with backoff and wraps as OpenMeteoException on exhaustion', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $ts = [CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC')->getTimestamp()];

    $http = FakeHttpTransport::fakeSequence(
        ProviderException::connectionError('GET', OpenMeteoConfig::DEFAULT_FORECAST_URL, 'DNS timeout'),
        new HttpResponse(200, (string) json_encode(makeOpenMeteoPayload($ts))),
    );
    $sleeper = new FakeSleeper;
    $limiter = new SlidingWindowRateLimiter(new InMemoryCacheStore, $sleeper);
    $client = new OpenMeteoClient($http, $limiter, $sleeper, new OpenMeteoConfig(maxRetries: 2));

    $result = $client->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'),
        CarbonImmutable::parse('2026-11-20 06:30:00', 'UTC'),
    );

    expect($result['endpoint'])->toBe('forecast')
        ->and($sleeper->sleepsMs)->toBe([1000]);

    // Exhausted connection errors throw OpenMeteoException::connectionError
    $failingHttp = FakeHttpTransport::fakeSequence(
        ProviderException::connectionError('GET', OpenMeteoConfig::DEFAULT_FORECAST_URL, 'Socket reset'),
        ProviderException::connectionError('GET', OpenMeteoConfig::DEFAULT_FORECAST_URL, 'Socket reset again'),
    );
    $failingSleeper = new FakeSleeper;
    $failingClient = new OpenMeteoClient(
        $failingHttp,
        new SlidingWindowRateLimiter(new InMemoryCacheStore, $failingSleeper),
        $failingSleeper,
        new OpenMeteoConfig(maxRetries: 1),
    );

    expect(fn (): array => $failingClient->fetchHourly(
        [['lat' => -36.8485, 'lng' => 174.7633]],
        CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'),
        CarbonImmutable::parse('2026-11-20 06:30:00', 'UTC'),
    ))->toThrow(OpenMeteoException::class, 'connection error');

    expect($failingSleeper->sleepsMs)->toBe([1000]);
});

it('propagates onRateLimitWait listener to RateLimiter::acquire when request budget is reached', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));

    $now = 1_700_000_000.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $cache = new InMemoryCacheStore($clock);
    $sleeper = new FakeSleeper(static function (int $ms) use (&$now): void {
        $now += $ms / 1000.0;
    });
    $limiter = new SlidingWindowRateLimiter($cache, $sleeper, $clock);

    $ts = [CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC')->getTimestamp()];
    $payload = (string) json_encode(makeOpenMeteoPayload($ts));
    $http = FakeHttpTransport::fakeSequence(
        new HttpResponse(200, $payload),
        new HttpResponse(200, $payload),
    );

    $client = new OpenMeteoClient($http, $limiter, $sleeper, new OpenMeteoConfig(maxRequestsPerMinute: 1));

    $waits = [];
    $client->onRateLimitWait(static function (float $waitSeconds, string $reason) use (&$waits): void {
        $waits[] = ['seconds' => $waitSeconds, 'reason' => $reason];
    });

    $windowStart = CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC');
    $windowEnd = CarbonImmutable::parse('2026-11-20 06:30:00', 'UTC');

    $client->fetchHourly([['lat' => -36.8485, 'lng' => 174.7633]], $windowStart, $windowEnd);
    $client->fetchHourly([['lat' => -36.8485, 'lng' => 174.7633]], $windowStart, $windowEnd);

    expect($waits)->toHaveCount(1)
        ->and($waits[0]['seconds'])->toBe(60.0)
        ->and($waits[0]['reason'])->toContain('1/min')
        ->and($sleeper->sleepsMs)->toBe([60000]);
});
