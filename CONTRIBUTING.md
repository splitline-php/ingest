# Contributing to Splitline Ingest

> [!IMPORTANT]
> All individual package repositories under `github.com/splitline-php/*` (`core`, `parsers`, `weather`, `laravel`, `provider-garmin-personal`, `provider-file-import`) are **read-only subtree splits** generated automatically from this monorepo. Please submit all issues and pull requests to [`splitline-php/ingest`](https://github.com/splitline-php/ingest).

## Requirements

- **PHP 8.5+** with `zip`, `curl`, `openssl`, and `mbstring`
- **Composer 2**

> [!NOTE]
> **Zero `ext-intl` and zero `ext-bcmath`:** CI runs with both `intl` and `bcmath` explicitly disabled to match Splitline's production Alpine Docker image. Do not use `bc*` functions, `intl` classes, or Laravel `Number::*` helpers anywhere in this repository.

## Setup

```bash
git clone https://github.com/splitline-php/ingest.git
cd splitline-ingest
composer install
```

## Quality Checks & Testing

Run the full CI check suite before opening a pull request:

```bash
composer ci:check
```

Individual commands:

| Check | Command | Purpose |
| :--- | :--- | :--- |
| **Laravel Pint (fix)** | `composer lint` | Format PHP code (`preset: laravel`) |
| **Laravel Pint (check)** | `composer lint:check` | Verify code formatting without modifying files |
| **PHPStan** | `composer types:check` | Static analysis at **Level 8 with no baseline** |
| **Pest** | `composer test` | Run unit and Laravel bridge feature tests |
| **Composer Manifests** | `composer validate --strict` | Validate root and `packages/*/composer.json` manifests |

Run targeted tests while iterating:

```bash
vendor/bin/pest tests/Unit/Core/ActivityClassifierTest.php
vendor/bin/pest --filter="test name"
```

## Coding & Architecture Guidelines

- **Stateless packages:** Keep `core`, `parsers`, `weather`, and `provider-*` pure PHP with zero Eloquent models, migrations, queues, or routes. Framework integration belongs exclusively in `packages/laravel/`.
- **Strict typing:** Use `declare(strict_types=1);`, explicit parameter and return types, `final readonly` DTOs, and PHPDoc array shapes so PHPStan Level 8 passes without a baseline.
- **SI / metric units:** All DTO telemetry values use SI/metric units (`meters`, `grams`, `seconds`, `m/s`, `°C`, `ml`, `km/h` for wind, `hPa`, `bpm`, `watts`).
- **Terminology:** Use **GNSS** (not GPS) and **Cycling** (not Biking) in docs and new identifiers.
- **No personal data in fixtures:** Never commit real personal telemetry, health exports, API keys, or GNSS tracks. All fixtures in `tests/Fixtures/` must be synthetic or thoroughly anonymized.
- **Adding a new provider:** Follow [ADDING_A_PROVIDER.md](ADDING_A_PROVIDER.md).

## Pull Requests

1. Branch from `main` with a descriptive name (e.g., `fix/fit-lap-timestamps` or `feat/provider-oura`).
2. Keep pull requests focused on a single change and include Pest tests covering new behavior and edge cases.
3. Ensure `composer ci:check` passes cleanly (and `composer validate --strict` passes if any `composer.json` changed).

## License

All packages in this monorepo are open-sourced under the [MIT License](LICENSE). By contributing, you agree that your contributions will be licensed under the MIT License.
