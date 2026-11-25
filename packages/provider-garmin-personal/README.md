# splitline/provider-garmin-personal

Garmin Connect Personal API telemetry provider for Splitline. Intended strictly for personal, non-commercial, self-hosted usage.

## Installation

```bash
composer require splitline/provider-garmin-personal
```

## Usage

```php
use Splitline\Core\Data\ProviderContext;
use Splitline\ProviderGarminPersonal\GarminPersonalProvider;

$context = new ProviderContext(
    scopeKey: 'default',
    timezone: 'UTC',
    credentials: ['api_key' => getenv('GARMIN_KEY')],
);

$activities = $provider->fetchActivities($context, offset: 0, limit: 20);
```

## Contributing

This repository is a **read-only subtree split**. Please submit all issues and pull requests to [splitline-php/ingest](https://github.com/splitline-php/ingest).

## License

MIT — see [LICENSE](LICENSE).
