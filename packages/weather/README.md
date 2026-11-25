# splitline/weather

Route waypoint sampling, wind-vector math, and Open-Meteo weather enrichment returning immutable `Splitline\Core\Data\ActivityWeatherData` DTOs.

## Installation

```bash
composer require splitline/weather
```

## Usage

```php
use Splitline\Weather\WindVectorMath;

$math = new WindVectorMath();
$bearing = $math->forwardBearing(-41.2865, 174.7762, -41.2924, 174.7787);
```

## Contributing

This repository is a **read-only subtree split**. Please submit all issues and pull requests to [splitline-php/ingest](https://github.com/splitline-php/ingest).

## License

MIT — see [LICENSE](LICENSE).
