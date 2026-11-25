<?php

declare(strict_types=1);

namespace Splitline\Core\Contracts;

interface Sleeper
{
    public function sleepMilliseconds(int $milliseconds): void;
}
