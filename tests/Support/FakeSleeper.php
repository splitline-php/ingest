<?php

declare(strict_types=1);

namespace Splitline\Tests\Support;

use Closure;
use Splitline\Core\Contracts\Sleeper;

final class FakeSleeper implements Sleeper
{
    /** @var list<int> */
    public array $sleepsMs = [];

    /** @var (Closure(int): void)|null */
    private ?Closure $onSleep;

    /**
     * @param  (Closure(int): void)|null  $onSleep
     */
    public function __construct(?Closure $onSleep = null)
    {
        $this->onSleep = $onSleep;
    }

    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        $this->sleepsMs[] = $milliseconds;

        if ($this->onSleep !== null) {
            ($this->onSleep)($milliseconds);
        }
    }

    public function totalMilliseconds(): int
    {
        return array_sum($this->sleepsMs);
    }

    public function reset(): void
    {
        $this->sleepsMs = [];
    }
}
