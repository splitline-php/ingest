<?php

declare(strict_types=1);

namespace Splitline\Weather\Exceptions;

use RuntimeException;
use Throwable;

class OpenMeteoException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $url = null,
        public readonly ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public static function httpError(
        string $url,
        int $statusCode,
        string $responseBody = '',
        ?int $retryAfterSeconds = null,
    ): self {
        $summary = trim($responseBody);
        $suffix = $summary !== '' ? ": {$summary}" : '';

        return new self(
            message: "Open-Meteo GET {$url} failed with HTTP {$statusCode}{$suffix}",
            statusCode: $statusCode,
            retryAfterSeconds: $retryAfterSeconds,
            url: $url,
            responseBody: $responseBody,
        );
    }

    public static function invalidResponse(string $url, string $reason): self
    {
        return new self(
            message: "Open-Meteo returned an invalid response from {$url}: {$reason}",
            url: $url,
        );
    }

    public static function connectionError(string $url, Throwable $previous): self
    {
        return new self(
            message: "Open-Meteo GET {$url} failed due to a connection error: {$previous->getMessage()}",
            url: $url,
            previous: $previous,
        );
    }

    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }

    /**
     * @return array{status_code: int|null, retry_after_seconds: int|null, url: string|null}
     */
    public function context(): array
    {
        return [
            'status_code' => $this->statusCode,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'url' => $this->url,
        ];
    }
}
