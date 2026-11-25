<?php

declare(strict_types=1);

namespace Splitline\Tests\Support;

use Closure;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Splitline\Core\Contracts\HttpTransport;
use Splitline\Core\Http\HttpRequest;
use Splitline\Core\Http\HttpResponse;
use Throwable;

final class FakeHttpTransport implements HttpTransport
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<HttpResponse|Throwable> */
    private array $queue = [];

    /** @var (Closure(HttpRequest): (HttpResponse|Throwable))|null */
    private ?Closure $responder = null;

    /**
     * @param  list<HttpResponse|Throwable>  $responses
     * @param  (Closure(HttpRequest): (HttpResponse|Throwable))|null  $responder
     */
    public function __construct(
        array $responses = [],
        ?Closure $responder = null,
    ) {
        $this->queue = array_values($responses);
        $this->responder = $responder;
    }

    public static function fakeSequence(HttpResponse|Throwable ...$responses): self
    {
        return new self(array_values($responses));
    }

    public function pushResponse(HttpResponse $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function pushException(Throwable $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    /**
     * @param  Closure(HttpRequest): (HttpResponse|Throwable)  $responder
     */
    public function respondWith(Closure $responder): self
    {
        $this->responder = $responder;

        return $this;
    }

    public function record(HttpRequest $request): void
    {
        $this->requests[] = $request;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->record($request);

        if ($this->queue !== []) {
            $next = array_shift($this->queue);

            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        if ($this->responder !== null) {
            $result = ($this->responder)($request);

            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result;
        }

        throw new RuntimeException(sprintf(
            'FakeHttpTransport has no queued response or responder for %s %s.',
            $request->method,
            $request->url,
        ));
    }

    /**
     * @return list<HttpRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function assertSentCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->requests,
            sprintf('Expected %d HTTP requests, but %d were sent.', $expected, count($this->requests)),
        );
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->queue = [];
        $this->responder = null;
    }
}
