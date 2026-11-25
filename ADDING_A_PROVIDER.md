# Adding a Provider

This guide explains how to contribute a new telemetry provider package (`splitline/provider-<vendor>`) to the `splitline-php/ingest` monorepo.

Every provider is a stateless PHP library that translates a vendor API, webhook payload, or file format into canonical `Splitline\Core\Data\*` DTOs. Host applications handle database storage, authentication UI, queues, and unit conversion.

---

## 4-Step Contributor Workflow

### 1. Scaffold `packages/provider-<vendor>`

Start by copying the closest existing provider package:

- **File-based or zero-auth provider:** copy `packages/provider-file-import/` (simplest starting point).
- **HTTP API pull provider:** copy `packages/provider-garmin-personal/` (includes config, token/credential handling, HTTP client, rate limiting, and payload mapper).

Update the new directory's files:

- `packages/provider-<vendor>/composer.json`:
  - Set `"name": "splitline/provider-<vendor>"`, `"license": "MIT"`, PSR-4 autoload namespace `Splitline\Provider<Vendor>\`, and `"extra": { "branch-alias": { "dev-main": "0.1-dev" } }`.
  - Depend only on `php: ^8.5`, `nesbot/carbon: ^3.0`, `splitline/core: ^0.1` (plus `splitline/parsers: ^0.1` if parsing FIT files).
  - Point `"homepage"` / `"support.source"` to `https://github.com/splitline-php/ingest` and `"support.issues"` to `https://github.com/splitline-php/ingest/issues`.
- `packages/provider-<vendor>/LICENSE`: copy the root `LICENSE` verbatim.
- `packages/provider-<vendor>/README.md`: short overview, install command, minimal usage example, and the read-only mirror notice pointing issues/PRs to `splitline-php/ingest`.

#### Credentials (OAuth 2.0 & Personal Access Tokens)

Design token-authenticated providers against `Splitline\Core\Contracts\CredentialProvider` and `Splitline\Core\Data\ProviderContext` rather than hard-coding a single authentication storage mechanism:

```php
$accessToken = $this->credentials->getAccessToken($context);
```

This single abstraction supports both deployment models cleanly:

- **Single-user / self-hosted apps (e.g., Splitline):** pass a Personal Access Token or API key via `$context->credentials` (or a simple static/env-backed `CredentialProvider`).
- **Multi-tenant SaaS apps (e.g., Ladbulance):** inject an OAuth 2.0 `CredentialProvider` that reads encrypted per-account tokens using `$context->scopeKey` (e.g., `account:{id}`) and refreshes expired tokens transparently when `$forceRefresh` is `true`.

---

### 2. Implement Core Contracts Returning `Splitline\Core\Data\*` DTOs

Implement `Splitline\Core\Contracts\TelemetryProvider` along with whichever capability interfaces match your vendor:

| Interface | When to Implement |
| :--- | :--- |
| `Splitline\Core\Contracts\PullProvider` | Vendor exposes REST/JSON endpoints to fetch activities, daily metrics, sleep, HRV, VO2 max, fitness age, weight, or heart-rate zones. |
| `Splitline\Core\Contracts\WebhookProvider` | Vendor pushes activity or health events to a webhook endpoint (`verifyWebhookSignature()` + `parseWebhook()` returning `WebhookParseResult`). |
| `Splitline\Core\Contracts\ActivityArchiveProvider` | Vendor lets you download raw activity files (`.fit`, `.zip`, `.gpx`, `.tcx`) for an external activity ID. |
| `Splitline\Core\Contracts\FileImportProvider` | Provider parses raw uploaded activity files into an `ImportedActivityBundle`. |

Map vendor payloads into the immutable DTOs under `Splitline\Core\Data\`:

- `NormalizedActivity` (use `ActivityClassifier::classify()` after mapping vendor sport names to Garmin-style `activity_type_key` strings, or `ActivityClassifier::classifyFitSport()` for FIT sport codes)
- `DailyHealthMetricData` (set `partialUpdate: true` and `fillOnlyWhenNullFields` when an endpoint supplies only a subset of daily fields)
- `SleepData`, `HrvData`, `Vo2MaxData`, `FitnessAgeData`, `WeightMeasurementData`, `WeightGoalData`, `HeartRateZoneData`
- `WebhookParseResult`, `ImportedActivityBundle`

Every DTO must populate `provider` with the provider's unique snake_case `id()` (e.g., `'oura'`, `'polar'`, `'wahoo'`).

---

### 3. Add Anonymized Fixtures and Unit Tests

- Add test fixtures under `tests/Fixtures/<Vendor>/` and unit tests under `tests/Unit/Provider<Vendor>/`.
- **Never commit real personal telemetry, health records, account identifiers, or GNSS tracks.** All JSON and FIT fixtures must be synthetic or thoroughly anonymized (neutral coordinates, synthetic timestamps, fake IDs).
- Test HTTP clients and providers offline using the shared test doubles in `tests/Support/`:
  - `Splitline\Tests\Support\FakeHttpTransport`
  - `Splitline\Tests\Support\FakeSleeper`
  - `Splitline\Tests\Support\InMemoryCacheStore`
- Cover happy paths, DTO field coercion, edge/null fields, error/retry handling (401 refresh, 429 cooldown, 5xx backoff), and capability flags.

---

### 4. Register the Package and Open a PR

1. Add the package to root `composer.json`:
   - Add `"splitline/provider-<vendor>": "self.version"` under `"replace"`.
   - Add `"Splitline\\Provider<Vendor>\\": "packages/provider-<vendor>/src/"` under `"autoload.psr-4"`.
2. Add the package to `.github/workflows/split.yml` (`matrix.include`) and to the `validate-packages` loop in `.github/workflows/ci.yml`.
3. Validate the manifest and run the full quality suite:
   ```bash
   composer validate --strict packages/provider-<vendor>/composer.json
   composer ci:check
   ```
4. Open a pull request against `main` on `splitline-php/ingest`.

---

## Non-Negotiable Rules

Every provider package in this repository must follow these rules:

1. **Zero `ext-intl` and zero `ext-bcmath`.**
   CI runs on PHP 8.5 with both extensions explicitly disabled (`:intl, :bcmath`) to match Splitline's production Alpine image. Do not call `bc*` functions, `NumberFormatter`, `IntlDateFormatter`, or Laravel `Number::*` helpers.

2. **Stateless, pure PHP only.**
   Provider packages must not contain Eloquent models, database migrations, queues, routes, or framework facades. Depend only on `Splitline\Core\Contracts\*` (`HttpTransport`, `CacheStore`, `RateLimiter`, `Sleeper`, `CredentialProvider`).

3. **SI / metric units everywhere in DTOs.**
   Convert any imperial or vendor-specific units inside your mapper before constructing DTOs:
   - Distance / elevation: `meters`
   - Mass / weight: `grams`
   - Duration: `seconds`
   - Speed: `m/s`
   - Temperature: `°C`
   - Fluid volume: `ml`
   - Wind speed: `km/h`
   - Pressure: `hPa`
   - Heart rate: `bpm`
   - Power: `watts`

4. **Accurate `ProviderCapabilities` and `allowsGroupSharing` compliance.**
   - Set `allowsGroupSharing: false` for any vendor whose API Terms of Service prohibit displaying one user's data to other users (for example, Strava). Multi-tenant consumers enforce this flag at ingestion time to prevent TOS violations in group feeds or leaderboards.
   - Set `requiresCommercialLicense: true` when commercial usage requires vendor approval or a paid license.

5. **Single-shot `HttpTransport` with client-owned retries.**
   `HttpTransport::send()` executes a single HTTP request, **never retries**, and **never throws on HTTP status codes** (4xx and 5xx responses are returned as `HttpResponse`; `ProviderException` is thrown only on connection/transport errors). Your provider client owns all retry, 401 token refresh, 429 `Retry-After` / cooldown, and 5xx backoff logic using `RateLimiter` and `Sleeper`.

6. **Honest `WebhookProvider::verifyWebhookSignature()` implementations.**
   Verify cryptographic signatures (e.g., HMAC-SHA256 with `hash_equals()`) whenever the vendor provides them. Return `true` **only** when the vendor does not sign webhook payloads, and in that case explicitly document the required alternative verification strategy (verification token, secret URL path, IP allowlist, etc.) in the provider class PHPDoc.

7. **Scoped cache and rate-limit keys via `ProviderContext::$scopeKey`.**
   Never use global cache keys or unscoped rate-limit buckets. Prefix all cache keys and rate-limiter buckets with your provider ID and `$context->scopeKey` (for example, `"oura:{$context->scopeKey}"`) so multi-tenant hosts can isolate tokens, metadata caches, and per-user rate limits across accounts.
