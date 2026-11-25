<?php

declare(strict_types=1);

namespace Splitline\Core\Exceptions;

use Throwable;

class ArchiveUnavailableException extends ProviderException
{
    public static function forActivity(
        string $url,
        int $statusCode,
        string $responseBody = '',
        ?Throwable $previous = null,
    ): self {
        $summary = trim($responseBody);
        $suffix = $summary !== '' ? ": {$summary}" : '';

        return new self(
            message: "Provider HTTP GET {$url} failed with HTTP {$statusCode}{$suffix}",
            method: 'GET',
            url: $url,
            statusCode: $statusCode,
            responseBody: $responseBody,
            previous: $previous,
        );
    }
}
