<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

use Closure;

final readonly class ProviderContext
{
    /**
     * @param  array<string, mixed>  $credentials
     * @param  (Closure(float, string): void)|null  $onRateLimitWait
     */
    public function __construct(
        public string $scopeKey,
        public string $timezone = 'UTC',
        public ?string $externalUserId = null,
        public array $credentials = [],
        public ?Closure $onRateLimitWait = null,
    ) {}

    /**
     * @param  (Closure(float, string): void)|null  $listener
     */
    public function withOnRateLimitWait(?Closure $listener): self
    {
        return new self(
            scopeKey: $this->scopeKey,
            timezone: $this->timezone,
            externalUserId: $this->externalUserId,
            credentials: $this->credentials,
            onRateLimitWait: $listener,
        );
    }
}
