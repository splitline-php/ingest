# splitline/core

Core telemetry contracts, immutable DTOs, sliding-window rate limiting, and pure PHP analytics math (`ActivityClassifier`, `BestEffortCalculator`) for the Splitline telemetry ecosystem.

## Installation

```bash
composer require splitline/core
```

## Usage

```php
use Splitline\Core\Analytics\ActivityClassifier;

$classifier = new ActivityClassifier();
$result = $classifier->classify('trail_running');
```

## Contributing

This repository is a **read-only subtree split**. Please submit all issues and pull requests to [splitline-php/ingest](https://github.com/splitline-php/ingest).

## License

MIT — see [LICENSE](LICENSE).
