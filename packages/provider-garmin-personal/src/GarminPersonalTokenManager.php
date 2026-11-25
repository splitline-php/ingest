<?php

declare(strict_types=1);

namespace Splitline\ProviderGarminPersonal;

use Carbon\CarbonImmutable;
use Splitline\Core\Contracts\CacheStore;
use Splitline\Core\Contracts\CredentialProvider;
use Splitline\Core\Contracts\HttpTransport;
use Splitline\Core\Data\ProviderContext;
use Splitline\Core\Exceptions\AuthenticationException;
use Splitline\Core\Exceptions\ProviderException;
use Splitline\Core\Http\HttpRequest;

final class GarminPersonalTokenManager implements CredentialProvider
{
    public const string TOKEN_PATH = '/personalapitoken-service/personal-api/personal-api-key/token';

    public const int REFRESH_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly CacheStore $cache,
        private readonly GarminPersonalConfig $config = new GarminPersonalConfig,
    ) {}

    public function getAccessToken(ProviderContext $context, bool $forceRefresh = false): string
    {
        $apiKey = $this->resolveApiKey($context);

        if ($apiKey === '') {
            throw new AuthenticationException('GARMIN_KEY is not configured.');
        }

        $cacheKey = $this->cacheKey($context->scopeKey, $apiKey);

        if (! $forceRefresh) {
            $cached = $this->cache->get($cacheKey);

            if (
                is_array($cached)
                && isset($cached['access_token'], $cached['expires_at'])
                && is_string($cached['access_token'])
                && is_int($cached['expires_at'])
                && ($cached['expires_at'] - CarbonImmutable::now()->getTimestamp()) > self::REFRESH_WINDOW_SECONDS
            ) {
                return $cached['access_token'];
            }
        }

        $baseUrl = rtrim($this->config->baseUrl, '/');
        $url = $baseUrl.self::TOKEN_PATH;

        $response = $this->http->send(new HttpRequest(
            method: 'POST',
            url: $url,
            headers: [
                'Accept' => '*/*',
                'Authorization' => 'ApiKey '.$apiKey,
            ],
            timeoutSeconds: $this->config->timeout,
            connectTimeoutSeconds: 5,
        ));

        if (! $response->successful()) {
            if ($response->status === 401 || $response->status === 403) {
                $summary = trim($response->body);
                $suffix = $summary !== '' ? ": {$summary}" : '';

                throw new AuthenticationException(
                    message: "Provider HTTP POST {$url} failed with HTTP {$response->status}{$suffix}",
                    method: 'POST',
                    url: $url,
                    statusCode: $response->status,
                    responseBody: $response->body,
                );
            }

            throw ProviderException::httpError('POST', $url, $response->status, $response->body);
        }

        $accessToken = trim($response->body);
        $expiresAt = $this->extractJwtExpiration($accessToken);
        $ttlSeconds = max(0, $expiresAt - CarbonImmutable::now()->getTimestamp() - self::REFRESH_WINDOW_SECONDS);

        if ($ttlSeconds > 0) {
            $this->cache->put(
                $cacheKey,
                [
                    'access_token' => $accessToken,
                    'expires_at' => $expiresAt,
                ],
                $ttlSeconds,
            );
        }

        return $accessToken;
    }

    public function forgetToken(ProviderContext $context): void
    {
        $apiKey = $this->resolveApiKey($context);

        if ($apiKey === '') {
            return;
        }

        $this->cache->forget($this->cacheKey($context->scopeKey, $apiKey));
    }

    public function cacheKey(string $scopeKey, string $apiKey): string
    {
        $fingerprint = hash('sha256', rtrim($this->config->baseUrl, '/')."\0".$apiKey);

        return "garmin_personal.{$scopeKey}.bearer_token.{$fingerprint}";
    }

    private function resolveApiKey(ProviderContext $context): string
    {
        $raw = $context->credentials['api_key'] ?? $this->config->defaultApiKey;

        return is_string($raw) ? trim($raw) : '';
    }

    private function extractJwtExpiration(string $accessToken): int
    {
        $segments = explode('.', $accessToken);

        if (count($segments) < 2) {
            throw new AuthenticationException(
                'Garmin token endpoint returned an invalid response: expected a JWT payload.',
            );
        }

        $encodedPayload = strtr($segments[1], '-_', '+/');
        $padding = strlen($encodedPayload) % 4;

        if ($padding !== 0) {
            $encodedPayload .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($encodedPayload, true);

        if ($decoded === false) {
            throw new AuthenticationException(
                'Garmin token endpoint returned an invalid response: unable to base64-decode JWT payload.',
            );
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload) || ! isset($payload['exp']) || ! is_numeric($payload['exp'])) {
            throw new AuthenticationException(
                'Garmin token endpoint returned an invalid response: JWT payload is missing a numeric exp claim.',
            );
        }

        return (int) $payload['exp'];
    }
}
