<?php

declare(strict_types=1);

namespace Splitline\Core\Http;

final readonly class HttpRequest
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public array $query = [],
        public ?string $body = null,
        public int $timeoutSeconds = 30,
        public int $connectTimeoutSeconds = 5,
    ) {}
}
