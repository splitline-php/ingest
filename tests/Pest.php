<?php

declare(strict_types=1);

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PHPUnit\Framework\TestCase;

pest()->extend(TestCase::class)->in('Unit');
pest()->extend(OrchestraTestCase::class)->in('Feature/Laravel');
