<?php

declare(strict_types=1);

namespace Splitline\Core\Http;

final readonly class HttpResponse
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    public function header(string $name): string
    {
        $needle = strtolower($name);

        foreach ($this->headers as $headerName => $values) {
            if (strtolower($headerName) === $needle) {
                return $values[0] ?? '';
            }
        }

        return '';
    }

    public function json(): mixed
    {
        if (trim($this->body) === '') {
            return null;
        }

        $decoded = json_decode($this->body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }
}
