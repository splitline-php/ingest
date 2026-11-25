<?php

declare(strict_types=1);

namespace Splitline\Core\Contracts;

interface CacheStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, int $ttlSeconds): void;

    public function forget(string $key): void;
}
