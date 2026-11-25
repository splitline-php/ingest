# splitline/laravel

Laravel bridge for the Splitline telemetry ecosystem. Binds `HttpTransport`, `CacheStore`, `Sleeper`, and `RateLimiter` to Laravel's `Http`, `Cache`, and `Sleep` primitives (preserving `Http::fake()` and `Sleep::fake()` in tests).

## Installation

```bash
composer require splitline/laravel
```

`Splitline\Laravel\SplitlineServiceProvider` is auto-discovered by Laravel.

## Contributing

This repository is a **read-only subtree split**. Please submit all issues and pull requests to [splitline-php/ingest](https://github.com/splitline-php/ingest).

## License

MIT — see [LICENSE](LICENSE).
