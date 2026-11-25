<?php

declare(strict_types=1);

namespace Splitline\Core\Exceptions;

use RuntimeException;
use Throwable;

class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $method = null,
        public readonly ?string $url = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    public static function httpError(
        string $method,
        string $url,
        int $statusCode,
        string $responseBody = '',
        ?Throwable $previous = null,
    ): self {
        $summary = trim($responseBody);
        $suffix = $summary !== '' ? ": {$summary}" : '';

        return new self(
            message: "Provider HTTP {$method} {$url} failed with HTTP {$statusCode}{$suffix}",
            method: $method,
            url: $url,
            statusCode: $statusCode,
            responseBody: $responseBody,
            previous: $previous,
        );
    }

    public static function connectionError(
        string $method,
        string $url,
        string $reason,
        ?Throwable $previous = null,
    ): self {
        $summary = trim($reason);
        $suffix = $summary !== '' ? ": {$summary}" : '';

        return new self(
            message: "Provider HTTP {$method} {$url} connection failed{$suffix}",
            method: $method,
            url: $url,
            statusCode: null,
            responseBody: null,
            previous: $previous,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'status_code' => $this->statusCode,
        ];
    }
}
