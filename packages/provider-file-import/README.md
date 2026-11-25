# splitline/provider-file-import

Standalone `.fit` and `.zip` activity file import provider for the Splitline telemetry ecosystem.

## Installation

```bash
composer require splitline/provider-file-import
```

## Usage

```php
use Splitline\ProviderFileImport\FitFileImportProvider;

$bundle = $provider->importBytes(file_get_contents('ride.fit'), 'ride.fit', 'Pacific/Auckland');
```

## Contributing

This repository is a **read-only subtree split**. Please submit all issues and pull requests to [splitline-php/ingest](https://github.com/splitline-php/ingest).

## License

MIT — see [LICENSE](LICENSE).
