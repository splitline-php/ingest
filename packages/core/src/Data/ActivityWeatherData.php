<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

/**
 * Aggregated weather summary and waypoint observations for an outdoor activity.
 *
 * @phpstan-import-type WeatherSampleArray from WeatherSampleData
 */
final readonly class ActivityWeatherData
{
    /**
     * @param  list<WeatherSampleData>  $samples
     */
    public function __construct(
        public string $provider,
        public string $modelEndpoint,
        public string $samplingMode,
        public int $waypointCount,
        public string $condition,
        public string $icon,
        public ?int $wmoCode = null,
        public ?string $baseCondition = null,
        public ?float $temperatureC = null,
        public ?float $minTemperatureC = null,
        public ?float $maxTemperatureC = null,
        public ?float $apparentTemperatureC = null,
        public ?int $humidityPct = null,
        public ?float $dewPointC = null,
        public ?float $windSpeedKmh = null,
        public ?float $windGustsKmh = null,
        public ?int $windDirectionDeg = null,
        public ?string $windDirectionCompass = null,
        public ?float $precipitationMm = null,
        public ?int $cloudCoverPct = null,
        public ?float $surfacePressureHpa = null,
        public ?float $headwindPct = null,
        public ?float $crosswindPct = null,
        public ?float $tailwindPct = null,
        public ?float $avgEffectiveWindKmh = null,
        public array $samples = [],
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     model_endpoint: string,
     *     sampling_mode: string,
     *     waypoint_count: int,
     *     wmo_code: ?int,
     *     condition: string,
     *     base_condition: ?string,
     *     icon: string,
     *     temperature_c: ?float,
     *     min_temperature_c: ?float,
     *     max_temperature_c: ?float,
     *     apparent_temperature_c: ?float,
     *     humidity_pct: ?int,
     *     dew_point_c: ?float,
     *     wind_speed_kmh: ?float,
     *     wind_gusts_kmh: ?float,
     *     wind_direction_deg: ?int,
     *     wind_direction_compass: ?string,
     *     precipitation_mm: ?float,
     *     cloud_cover_pct: ?int,
     *     surface_pressure_hpa: ?float,
     *     headwind_pct: ?float,
     *     crosswind_pct: ?float,
     *     tailwind_pct: ?float,
     *     avg_effective_wind_kmh: ?float,
     *     samples: list<WeatherSampleArray>
     * }
     */
    public function toArray(): array
    {
        $samples = [];

        foreach ($this->samples as $sample) {
            $samples[] = $sample->toArray();
        }

        return [
            'provider' => $this->provider,
            'model_endpoint' => $this->modelEndpoint,
            'sampling_mode' => $this->samplingMode,
            'waypoint_count' => $this->waypointCount,
            'wmo_code' => $this->wmoCode,
            'condition' => $this->condition,
            'base_condition' => $this->baseCondition,
            'icon' => $this->icon,
            'temperature_c' => $this->temperatureC,
            'min_temperature_c' => $this->minTemperatureC,
            'max_temperature_c' => $this->maxTemperatureC,
            'apparent_temperature_c' => $this->apparentTemperatureC,
            'humidity_pct' => $this->humidityPct,
            'dew_point_c' => $this->dewPointC,
            'wind_speed_kmh' => $this->windSpeedKmh,
            'wind_gusts_kmh' => $this->windGustsKmh,
            'wind_direction_deg' => $this->windDirectionDeg,
            'wind_direction_compass' => $this->windDirectionCompass,
            'precipitation_mm' => $this->precipitationMm,
            'cloud_cover_pct' => $this->cloudCoverPct,
            'surface_pressure_hpa' => $this->surfacePressureHpa,
            'headwind_pct' => $this->headwindPct,
            'crosswind_pct' => $this->crosswindPct,
            'tailwind_pct' => $this->tailwindPct,
            'avg_effective_wind_kmh' => $this->avgEffectiveWindKmh,
            'samples' => $samples,
        ];
    }
}
