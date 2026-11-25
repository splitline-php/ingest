<?php

declare(strict_types=1);

namespace Splitline\Core\Exceptions;

use Throwable;

class RateLimitedException extends ProviderException
{
    public function __construct(
        string $message,
        ?string $method = null,
        ?string $url = null,
        ?int $statusCode = 429,
        ?string $responseBody = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $method, $url, $statusCode, $responseBody, $previous);
    }

    public static function fromResponse(
        string $method,
        string $url,
        int $statusCode = 429,
        string $responseBody = '',
        ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ): self {
        $summary = trim($responseBody);
        $suffix = $summary !== '' ? ": {$summary}" : '';

        return new self(
            message: "Provider HTTP {$method} {$url} rate limited with HTTP {$statusCode}{$suffix}",
            method: $method,
            url: $url,
            statusCode: $statusCode,
            responseBody: $responseBody,
            retryAfterSeconds: $retryAfterSeconds,
            previous: $previous,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            ...parent::context(),
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }
}
