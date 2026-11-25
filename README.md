# Splitline Ingest

Open-source PHP 8.5 monorepo for endurance telemetry contracts, binary FIT file parsing, route weather enrichment, Laravel infrastructure bindings, and wearable provider integrations.

All packages are MIT-licensed, stateless, strictly typed (PHPStan Level 8, no baseline), and intentionally free of `ext-intl` and `ext-bcmath`.

## Packages

Each directory under `packages/` is published to Packagist via automated read-only subtree splits under [`github.com/splitline-php`](https://github.com/splitline-php):

| Package | Namespace | Description |
| :--- | :--- | :--- |
| [`splitline/core`](packages/core) | `Splitline\Core\` | Provider & transport contracts, immutable SI/metric DTOs, `SlidingWindowRateLimiter`, `PayloadCoercer`, `ActivityClassifier`, and `BestEffortCalculator`. |
| [`splitline/parsers`](packages/parsers) | `Splitline\Parsers\` | Pure-PHP binary Garmin `.fit` and `.zip` archive parser (`FitParser`) producing `ActivityStreamData`. Requires `ext-zip`. |
| [`splitline/weather`](packages/weather) | `Splitline\Weather\` | GNSS waypoint sampling (`ActivityWaypointSampler`), headwind/crosswind vector math (`WindVectorMath`), Open-Meteo client (free & commercial), and `WeatherEnricher`. |
| [`splitline/laravel`](packages/laravel) | `Splitline\Laravel\` | Laravel 13 bridge implementing `HttpTransport`, `CacheStore`, and `Sleeper` (`LaravelHttpTransport`, `LaravelCacheStore`, `LaravelSleeper`) with auto-discovered `SplitlineServiceProvider`. |
| [`splitline/provider-garmin-personal`](packages/provider-garmin-personal) | `Splitline\ProviderGarminPersonal\` | Garmin Connect Personal API pull & archive provider. **Personal, non-commercial, self-hosted use only.** |
| [`splitline/provider-file-import`](packages/provider-file-import) | `Splitline\ProviderFileImport\` | Standalone `.fit` / `.zip` file import provider (`FitFileImportProvider`) with deterministic SHA-256 activity deduplication. |

## Dependency Graph

```text
                    ┌─────────────────────┐
                    │   splitline/core    │  (PHP ^8.5, nesbot/carbon ^3.0)
                    └──────────┬──────────┘
         ┌─────────────────────┼─────────────────────┬────────────────────────────┐
         ▼                     ▼                     ▼                            ▼
┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐   ┌──────────────────────────────┐
│splitline/parsers│   │splitline/weather│   │splitline/laravel│   │splitline/provider-garmin-    │
│ (ext-zip)       │   │                 │   │ (illuminate/*)  │   │  personal (personal only)    │
└────────┬────────┘   └─────────────────┘   └─────────────────┘   └──────────────────────────────┘
         ▼
┌──────────────────────────────┐
│splitline/provider-file-import│
└──────────────────────────────┘
```

## Installation

Install only the split packages you need — do **not** require `splitline/ingest` directly.

### Self-Hosted / Personal Applications (e.g., Splitline)

```bash
composer require \
  splitline/core \
  splitline/parsers \
  splitline/weather \
  splitline/laravel \
  splitline/provider-garmin-personal \
  splitline/provider-file-import
```

### Commercial / Multi-Tenant Applications

```bash
composer require \
  splitline/core \
  splitline/parsers \
  splitline/weather \
  splitline/laravel \
  splitline/provider-file-import
```

### Commercial vs. Personal API Separation

`splitline/provider-garmin-personal` targets Garmin's Personal API and is intended **strictly for personal, non-commercial, self-hosted deployments**. Commercial or multi-tenant applications must **never** depend on `splitline/provider-garmin-personal`; instead, implement a commercial provider (such as Garmin Health API via OAuth 2.0 PKCE + webhooks) against the same `Splitline\Core\Contracts\*` interfaces.

For weather enrichment, `splitline/weather` defaults to Open-Meteo's free endpoints (with CC BY 4.0 attribution) and provides `OpenMeteoConfig::commercial($apiKey)` for commercial deployments using Open-Meteo customer endpoints.

## Operational Notes

### Rate Limiter Concurrency

`Splitline\Core\Http\SlidingWindowRateLimiter` tracks request timestamps and cooldown windows using non-atomic `CacheStore::get()` / `CacheStore::put()` calls without distributed locks. Request budgets are therefore **approximate across concurrent queue workers**. Single-worker or self-hosted deployments are unaffected; high-concurrency multi-worker deployments should account for slight burst overlap or supply a custom `RateLimiter` implementation.

## Adding a Provider

Want to add support for Oura, Polar, Suunto, Wahoo, Whoop, or another wearable or file format? See [ADDING_A_PROVIDER.md](ADDING_A_PROVIDER.md) for the 4-step contributor guide and non-negotiable contract rules.

## Development & Contributing

This repository is the single source of truth for all `splitline/*` packages. Individual mirror repositories under `github.com/splitline-php/*` are read-only subtree splits — please open all issues and pull requests here.

```bash
composer install
composer ci:check
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for prerequisites, tooling commands, and coding conventions.

## License

MIT — see [LICENSE](LICENSE).
