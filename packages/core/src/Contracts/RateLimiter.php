<?php

declare(strict_types=1);

namespace Splitline\Core\Contracts;

interface RateLimiter
{
    /**
     * Block/sleep until a request slot is available for the given bucket key.
     *
     * @param  (callable(float, string): void)|null  $onWait
     */
    public function acquire(
        string $bucketKey,
        int $maxRequestsPerMinute,
        int $maxWaitSeconds = 120,
        ?callable $onWait = null,
    ): void;

    /**
     * Record a server-requested cooldown (e.g. after HTTP 429).
     */
    public function recordCooldown(string $bucketKey, float $seconds): void;
}
