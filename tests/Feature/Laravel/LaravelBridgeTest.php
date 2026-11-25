<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Splitline\Core\Analytics\ActivityClassifier;
use Splitline\Core\Analytics\BestEffortCalculator;
use Splitline\Core\Contracts\CacheStore;
use Splitline\Core\Contracts\HttpTransport;
use Splitline\Core\Contracts\RateLimiter;
use Splitline\Core\Contracts\Sleeper;
use Splitline\Core\Exceptions\ProviderException;
use Splitline\Core\Http\HttpRequest;
use Splitline\Core\Http\HttpResponse;
use Splitline\Core\Http\SlidingWindowRateLimiter;
use Splitline\Laravel\LaravelCacheStore;
use Splitline\Laravel\LaravelHttpTransport;
use Splitline\Laravel\LaravelSleeper;
use Splitline\Laravel\SplitlineServiceProvider;

beforeEach(function (): void {
    $this->app->register(SplitlineServiceProvider::class);
});

it('registers splitline core and laravel bridge singletons in the service container', function (): void {
    expect($this->app->make(HttpTransport::class))->toBeInstanceOf(LaravelHttpTransport::class)
        ->and($this->app->make(HttpTransport::class))->toBe($this->app->make(HttpTransport::class))
        ->and($this->app->make(CacheStore::class))->toBeInstanceOf(LaravelCacheStore::class)
        ->and($this->app->make(CacheStore::class))->toBe($this->app->make(CacheStore::class))
        ->and($this->app->make(Sleeper::class))->toBeInstanceOf(LaravelSleeper::class)
        ->and($this->app->make(Sleeper::class))->toBe($this->app->make(Sleeper::class))
        ->and($this->app->make(RateLimiter::class))->toBeInstanceOf(SlidingWindowRateLimiter::class)
        ->and($this->app->make(RateLimiter::class))->toBe($this->app->make(RateLimiter::class))
        ->and($this->app->make(ActivityClassifier::class))->toBeInstanceOf(ActivityClassifier::class)
        ->and($this->app->make(ActivityClassifier::class))->toBe($this->app->make(ActivityClassifier::class))
        ->and($this->app->make(BestEffortCalculator::class))->toBeInstanceOf(BestEffortCalculator::class)
        ->and($this->app->make(BestEffortCalculator::class))->toBe($this->app->make(BestEffortCalculator::class));
});

it('sends headers, query parameters, and request body via LaravelHttpTransport and returns 200 responses', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/v1/activities*' => Http::response(
            ['synced' => true],
            200,
            ['X-Request-Id' => 'req-123'],
        ),
    ]);

    /** @var HttpTransport $transport */
    $transport = $this->app->make(HttpTransport::class);

    $response = $transport->send(new HttpRequest(
        method: 'POST',
        url: 'https://api.example.test/v1/activities',
        headers: [
            'Authorization' => 'Bearer test-token',
            'Content-Type' => 'application/json',
        ],
        query: ['limit' => 25, 'cursor' => 'abc'],
        body: '{"sport":"running"}',
    ));

    expect($response)->toBeInstanceOf(HttpResponse::class)
        ->and($response->status)->toBe(200)
        ->and($response->successful())->toBeTrue()
        ->and($response->json())->toBe(['synced' => true])
        ->and($response->header('x-request-id'))->toBe('req-123');

    Http::assertSent(static function (Request $request): bool {
        return $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://api.example.test/v1/activities')
            && str_contains($request->url(), 'limit=25')
            && str_contains($request->url(), 'cursor=abc')
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->body() === '{"sport":"running"}';
    });
});

it('returns 429 and 500 responses as HttpResponse without throwing', function (
    int $status,
    string $body,
    array $headers,
): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/v1/status' => Http::response($body, $status, $headers),
    ]);

    /** @var HttpTransport $transport */
    $transport = $this->app->make(HttpTransport::class);

    $response = $transport->send(new HttpRequest(
        method: 'GET',
        url: 'https://api.example.test/v1/status',
    ));

    expect($response)->toBeInstanceOf(HttpResponse::class)
        ->and($response->status)->toBe($status)
        ->and($response->body)->toBe($body)
        ->and($response->successful())->toBeFalse()
        ->and($response->serverError())->toBe($status >= 500);

    if (isset($headers['Retry-After'])) {
        expect($response->header('Retry-After'))->toBe($headers['Retry-After']);
    }
})->with([
    '429 rate limited' => [429, 'Too Many Requests', ['Retry-After' => '30']],
    '500 server error' => [500, 'Internal Server Error', []],
]);

it('converts ConnectionException into ProviderException', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.example.test/v1/timeout' => static function (): never {
            throw new ConnectionException('Connection timed out');
        },
    ]);

    /** @var HttpTransport $transport */
    $transport = $this->app->make(HttpTransport::class);

    try {
        $transport->send(new HttpRequest(
            method: 'GET',
            url: 'https://api.example.test/v1/timeout',
        ));
        $this->fail('Expected ProviderException to be thrown.');
    } catch (ProviderException $exception) {
        expect($exception->getMessage())->toBe('Connection timed out')
            ->and($exception->method)->toBe('GET')
            ->and($exception->url)->toBe('https://api.example.test/v1/timeout')
            ->and($exception->statusCode)->toBeNull()
            ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

it('stores, retrieves, and forgets cache entries through LaravelCacheStore', function (): void {
    /** @var CacheStore $cache */
    $cache = $this->app->make(CacheStore::class);

    expect($cache->get('splitline.test.missing', 'fallback'))->toBe('fallback');

    $cache->put('splitline.test.key', ['count' => 3], 60);

    expect($cache->get('splitline.test.key'))->toBe(['count' => 3]);

    $cache->forget('splitline.test.key');

    expect($cache->get('splitline.test.key'))->toBeNull();
});

it('delegates positive sleep durations to Sleep::fake() and skips non-positive durations', function (): void {
    Sleep::fake();

    /** @var Sleeper $sleeper */
    $sleeper = $this->app->make(Sleeper::class);

    $sleeper->sleepMilliseconds(0);
    $sleeper->sleepMilliseconds(-250);

    Sleep::assertNeverSlept();

    $sleeper->sleepMilliseconds(350);

    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([
        Sleep::for(350)->milliseconds(),
    ]);
});
