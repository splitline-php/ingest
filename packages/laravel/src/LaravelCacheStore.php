<?php

declare(strict_types=1);

namespace Splitline\Laravel;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Splitline\Core\Contracts\CacheStore;

final class LaravelCacheStore implements CacheStore
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($key, $default);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->cache->put($key, $value, $ttlSeconds);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }
}
