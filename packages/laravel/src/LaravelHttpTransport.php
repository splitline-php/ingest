<?php

declare(strict_types=1);

namespace Splitline\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Splitline\Core\Contracts\HttpTransport;
use Splitline\Core\Exceptions\ProviderException;
use Splitline\Core\Http\HttpRequest;
use Splitline\Core\Http\HttpResponse;
use Stringable;

final class LaravelHttpTransport implements HttpTransport
{
    public function __construct(
        private readonly ?Container $container = null,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $options = [];

        if ($request->query !== []) {
            $options['query'] = $request->query;
        }

        if ($request->body !== null) {
            $options['body'] = $request->body;
        }

        try {
            $response = $this->resolveFactory()
                ->createPendingRequest()
                ->connectTimeout($request->connectTimeoutSeconds)
                ->timeout($request->timeoutSeconds)
                ->withHeaders($request->headers)
                ->send($request->method, $request->url, $options);
        } catch (ConnectionException $e) {
            throw new ProviderException(
                message: $e->getMessage(),
                method: $request->method,
                url: $request->url,
                previous: $e,
            );
        }

        return new HttpResponse(
            $response->status(),
            $response->body(),
            $this->normalizeHeaders($response->headers()),
        );
    }

    private function resolveFactory(): Factory
    {
        if ($this->container !== null) {
            return $this->container->make(Factory::class);
        }

        $root = Http::getFacadeRoot();

        if ($root instanceof Factory) {
            return $root;
        }

        return new Factory;
    }

    /**
     * @param  array<array-key, mixed>  $rawHeaders
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(array $rawHeaders): array
    {
        $normalized = [];

        foreach ($rawHeaders as $name => $values) {
            $key = (string) $name;

            if (is_array($values)) {
                $normalized[$key] = array_values(array_map(
                    static fn (mixed $value): string => is_scalar($value) || $value instanceof Stringable ? (string) $value : '',
                    $values,
                ));
            } else {
                $normalized[$key] = [
                    is_scalar($values) || $values instanceof Stringable ? (string) $values : '',
                ];
            }
        }

        return $normalized;
    }
}
