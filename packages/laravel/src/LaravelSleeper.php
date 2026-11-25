<?php

declare(strict_types=1);

namespace Splitline\Laravel;

use Illuminate\Support\Sleep;
use Splitline\Core\Contracts\Sleeper;

final class LaravelSleeper implements Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        Sleep::for($milliseconds)->milliseconds();
    }
}
