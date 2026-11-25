<?php

declare(strict_types=1);

namespace Splitline\Laravel;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Splitline\Core\Analytics\ActivityClassifier;
use Splitline\Core\Analytics\BestEffortCalculator;
use Splitline\Core\Contracts\CacheStore;
use Splitline\Core\Contracts\HttpTransport;
use Splitline\Core\Contracts\RateLimiter;
use Splitline\Core\Contracts\Sleeper;
use Splitline\Core\Http\SlidingWindowRateLimiter;

final class SplitlineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            HttpTransport::class,
            static fn (Container $app): HttpTransport => new LaravelHttpTransport($app),
        );

        $this->app->singleton(
            CacheStore::class,
            static fn (Container $app): CacheStore => new LaravelCacheStore(
                $app->make(CacheRepository::class),
            ),
        );

        $this->app->singleton(Sleeper::class, LaravelSleeper::class);

        $this->app->singleton(
            RateLimiter::class,
            static fn (Container $app): RateLimiter => new SlidingWindowRateLimiter(
                $app->make(CacheStore::class),
                $app->make(Sleeper::class),
            ),
        );

        $this->app->singleton(ActivityClassifier::class);
        $this->app->singleton(BestEffortCalculator::class);
    }
}
