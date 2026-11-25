<?php

declare(strict_types=1);

namespace Splitline\Core\Contracts;

use Splitline\Core\Data\WebhookParseResult;

interface WebhookProvider extends TelemetryProvider
{
    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool;

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function parseWebhook(string $rawBody, array $headers): WebhookParseResult;
}
