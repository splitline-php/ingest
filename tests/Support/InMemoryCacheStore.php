<?php

declare(strict_types=1);

namespace Splitline\Tests\Support;

use Carbon\CarbonImmutable;
use Closure;
use Splitline\Core\Contracts\CacheStore;

final class InMemoryCacheStore implements CacheStore
{
    /** @var array<string, array{value: mixed, expires_at: float}> */
    private array $entries = [];

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => CarbonImmutable::now()->getPreciseTimestamp(3) / 1000.0;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, $this->entries)) {
            return $default;
        }

        if ($this->entries[$key]['expires_at'] <= $this->now()) {
            unset($this->entries[$key]);

            return $default;
        }

        return $this->entries[$key]['value'];
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            unset($this->entries[$key]);

            return;
        }

        $this->entries[$key] = [
            'value' => $value,
            'expires_at' => $this->now() + (float) $ttlSeconds,
        ];
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function has(string $key): bool
    {
        if (! array_key_exists($key, $this->entries)) {
            return false;
        }

        if ($this->entries[$key]['expires_at'] <= $this->now()) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->pruneExpired();

        $values = [];

        foreach ($this->entries as $key => $entry) {
            $values[$key] = $entry['value'];
        }

        return $values;
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    private function now(): float
    {
        return ($this->clock)();
    }

    private function pruneExpired(): void
    {
        $now = $this->now();

        foreach ($this->entries as $key => $entry) {
            if ($entry['expires_at'] <= $now) {
                unset($this->entries[$key]);
            }
        }
    }
}
