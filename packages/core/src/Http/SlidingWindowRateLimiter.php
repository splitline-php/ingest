<?php

declare(strict_types=1);

namespace Splitline\Core\Http;

use Carbon\CarbonImmutable;
use Closure;
use Splitline\Core\Contracts\CacheStore;
use Splitline\Core\Contracts\RateLimiter;
use Splitline\Core\Contracts\Sleeper;

/**
 * Cache-backed sliding-window rate limiter with shared server-requested cooldown tracking.
 *
 * Note: Cache reads and writes are intentionally non-atomic (get/put without distributed locks),
 * so enforced request budgets are approximate across concurrent workers.
 */
final class SlidingWindowRateLimiter implements RateLimiter
{
    private const float WINDOW_SECONDS = 60.0;

    private const float WINDOW_BUFFER_SECONDS = 0.25;

    private const int TIMESTAMPS_TTL_SECONDS = 120;

    /**
     * @var Closure(): float
     */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        private readonly CacheStore $cache,
        private readonly Sleeper $sleeper,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => CarbonImmutable::now()->getPreciseTimestamp(3) / 1000.0;
    }

    public static function timestampsCacheKey(string $bucketKey): string
    {
        return "splitline.rate_limit.{$bucketKey}.timestamps";
    }

    public static function cooldownUntilCacheKey(string $bucketKey): string
    {
        return "splitline.rate_limit.{$bucketKey}.cooldown_until";
    }

    public function acquire(
        string $bucketKey,
        int $maxRequestsPerMinute,
        int $maxWaitSeconds = 120,
        ?callable $onWait = null,
    ): void {
        $now = ($this->clock)();
        $cooldownKey = self::cooldownUntilCacheKey($bucketKey);
        $cooldownUntil = $this->cache->get($cooldownKey);

        if (is_numeric($cooldownUntil) && (float) $cooldownUntil > $now) {
            $waitSeconds = min(
                (float) $cooldownUntil - $now,
                (float) max(1, $maxWaitSeconds),
            );

            if ($onWait !== null) {
                $onWait($waitSeconds, 'Rate limit cooldown');
            }

            $this->sleepSeconds($waitSeconds);
            $now = ($this->clock)();
        }

        if ($maxRequestsPerMinute <= 0) {
            return;
        }

        $timestampsKey = self::timestampsCacheKey($bucketKey);

        while (true) {
            $timestamps = $this->pruneRequestTimestamps(
                $this->cache->get($timestampsKey),
                $now,
            );

            if (count($timestamps) < $maxRequestsPerMinute) {
                $timestamps[] = $now;
                $this->cache->put($timestampsKey, $timestamps, self::TIMESTAMPS_TTL_SECONDS);

                return;
            }

            $oldestTimestamp = $timestamps[0];
            $waitSeconds = ($oldestTimestamp + self::WINDOW_SECONDS)
                - $now
                + self::WINDOW_BUFFER_SECONDS;
            $waitSeconds = max(0.1, min($waitSeconds, (float) max(1, $maxWaitSeconds)));

            if ($onWait !== null) {
                $onWait(
                    $waitSeconds,
                    "Request budget ({$maxRequestsPerMinute}/min) reached",
                );
            }

            $beforeSleep = $now;
            $this->sleepSeconds($waitSeconds);
            $now = ($this->clock)();

            if ($now <= $beforeSleep) {
                array_shift($timestamps);
                $timestamps[] = $now;
                $this->cache->put($timestampsKey, $timestamps, self::TIMESTAMPS_TTL_SECONDS);

                return;
            }
        }
    }

    public function recordCooldown(string $bucketKey, float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        $cooldownKey = self::cooldownUntilCacheKey($bucketKey);
        $now = ($this->clock)();
        $targetUntil = $now + $seconds;
        $existingUntil = $this->cache->get($cooldownKey);

        if (is_numeric($existingUntil)) {
            $targetUntil = max((float) $existingUntil, $targetUntil);
        }

        $remainingSeconds = max($seconds, $targetUntil - $now);
        $ttl = max(60, (int) ceil($remainingSeconds) + 60);

        $this->cache->put($cooldownKey, $targetUntil, $ttl);
    }

    /**
     * @return list<float>
     */
    private function pruneRequestTimestamps(mixed $raw, float $now): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $cutoff = $now - self::WINDOW_SECONDS;
        $valid = [];

        foreach ($raw as $timestamp) {
            if (is_numeric($timestamp) && (float) $timestamp > $cutoff) {
                $valid[] = (float) $timestamp;
            }
        }

        sort($valid);

        return $valid;
    }

    private function sleepSeconds(float $seconds): void
    {
        $milliseconds = (int) ceil($seconds * 1000);

        if ($milliseconds > 0) {
            $this->sleeper->sleepMilliseconds($milliseconds);
        }
    }
}
