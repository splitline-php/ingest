<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Splitline\Core\Http\SlidingWindowRateLimiter;
use Splitline\Tests\Support\FakeSleeper;
use Splitline\Tests\Support\InMemoryCacheStore;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('builds deterministic cache keys for request timestamps and cooldowns', function (): void {
    expect(SlidingWindowRateLimiter::timestampsCacheKey('garmin'))
        ->toBe('splitline.rate_limit.garmin.timestamps')
        ->and(SlidingWindowRateLimiter::cooldownUntilCacheKey('garmin'))
        ->toBe('splitline.rate_limit.garmin.cooldown_until');
});

it('enforces the sliding-window request cap with a 0.25s buffer and invokes onWait', function (): void {
    $now = 1_700_000_000.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $cache = new InMemoryCacheStore($clock);
    $sleeper = new FakeSleeper(static function (int $ms) use (&$now): void {
        $now += $ms / 1000.0;
    });
    $limiter = new SlidingWindowRateLimiter($cache, $sleeper, $clock);

    $waits = [];
    $onWait = static function (float $waitSeconds, string $reason) use (&$waits): void {
        $waits[] = ['seconds' => $waitSeconds, 'reason' => $reason];
    };

    $limiter->acquire('garmin', 2, 120, $onWait);
    $limiter->acquire('garmin', 2, 120, $onWait);

    expect($sleeper->sleepsMs)->toBe([])
        ->and($waits)->toBe([])
        ->and($cache->get('splitline.rate_limit.garmin.timestamps'))
        ->toBe([1_700_000_000.0, 1_700_000_000.0]);

    $limiter->acquire('garmin', 2, 120, $onWait);

    expect($sleeper->sleepsMs)->toBe([60250])
        ->and($waits)->toBe([
            [
                'seconds' => 60.25,
                'reason' => 'Request budget (2/min) reached',
            ],
        ])
        ->and($cache->get('splitline.rate_limit.garmin.timestamps'))
        ->toBe([1_700_000_060.25]);
});

it('expires only requests older than 60 seconds when sliding the window forward', function (): void {
    $now = 1_700_000_000.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $cache = new InMemoryCacheStore($clock);
    $sleeper = new FakeSleeper(static function (int $ms) use (&$now): void {
        $now += $ms / 1000.0;
    });
    $limiter = new SlidingWindowRateLimiter($cache, $sleeper, $clock);

    $limiter->acquire('garmin', 2);
    $now = 1_700_000_020.0;
    $limiter->acquire('garmin', 2);
    $now = 1_700_000_045.0;
    $limiter->acquire('garmin', 2);

    expect($sleeper->sleepsMs)->toBe([15250])
        ->and($cache->get('splitline.rate_limit.garmin.timestamps'))
        ->toBe([1_700_000_020.0, 1_700_000_060.25]);
});

it('enforces recorded cooldowns without shortening an existing longer cooldown', function (): void {
    $now = 1_700_000_000.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $cache = new InMemoryCacheStore($clock);
    $sleeper = new FakeSleeper(static function (int $ms) use (&$now): void {
        $now += $ms / 1000.0;
    });
    $limiter = new SlidingWindowRateLimiter($cache, $sleeper, $clock);

    $limiter->recordCooldown('garmin', 0.0);
    $limiter->recordCooldown('garmin', -5.0);

    expect($cache->has('splitline.rate_limit.garmin.cooldown_until'))->toBeFalse();

    $limiter->recordCooldown('garmin', 15.0);
    $limiter->recordCooldown('garmin', 5.0);

    expect($cache->get('splitline.rate_limit.garmin.cooldown_until'))->toBe(1_700_000_015.0);

    $waits = [];
    $limiter->acquire('garmin', 10, 120, static function (float $waitSeconds, string $reason) use (&$waits): void {
        $waits[] = ['seconds' => $waitSeconds, 'reason' => $reason];
    });

    expect($sleeper->sleepsMs)->toBe([15000])
        ->and($waits)->toBe([
            [
                'seconds' => 15.0,
                'reason' => 'Rate limit cooldown',
            ],
        ])
        ->and($cache->get('splitline.rate_limit.garmin.timestamps'))
        ->toBe([1_700_000_015.0]);
});

it('caps cooldown wait duration at maxWaitSeconds and skips request tracking when budget is non-positive', function (): void {
    $now = 1_700_000_000.0;
    $clock = static function () use (&$now): float {
        return $now;
    };
    $cache = new InMemoryCacheStore($clock);
    $sleeper = new FakeSleeper(static function (int $ms) use (&$now): void {
        $now += $ms / 1000.0;
    });
    $limiter = new SlidingWindowRateLimiter($cache, $sleeper, $clock);

    $limiter->recordCooldown('garmin', 45.0);
    $limiter->acquire('garmin', 0, 10);

    expect($sleeper->sleepsMs)->toBe([10000])
        ->and($cache->has('splitline.rate_limit.garmin.timestamps'))->toBeFalse();
});

it('uses the default CarbonImmutable clock which respects CarbonImmutable::setTestNow()', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(1_700_000_000));

    $cache = new InMemoryCacheStore;
    $sleeper = new FakeSleeper(static function (int $ms): void {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds($ms));
    });
    $limiter = new SlidingWindowRateLimiter($cache, $sleeper);

    $limiter->acquire('default-clock', 1);
    $limiter->acquire('default-clock', 1);

    expect($sleeper->sleepsMs)->toBe([60250])
        ->and($cache->get('splitline.rate_limit.default-clock.timestamps'))
        ->toBe([1_700_000_060.25]);
});
