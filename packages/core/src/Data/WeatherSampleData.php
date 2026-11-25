<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

/**
 * Single interpolated weather observation sampled along an activity route or start point.
 *
 * @phpstan-type WeatherSampleArray array{
 *     time_offset_s: int,
 *     distance_m: float,
 *     lat: float,
 *     lng: float,
 *     wmo_code: ?int,
 *     temperature_c: ?float,
 *     apparent_temperature_c: ?float,
 *     humidity_pct: ?int,
 *     dew_point_c: ?float,
 *     wind_speed_kmh: ?float,
 *     wind_gusts_kmh: ?float,
 *     wind_direction_deg: ?int,
 *     wind_direction_compass: ?string,
 *     precipitation_mm: ?float,
 *     cloud_cover_pct: ?int,
 *     surface_pressure_hpa: ?float
 * }
 */
final readonly class WeatherSampleData
{
    public function __construct(
        public int $timeOffsetS,
        public float $distanceM,
        public float $lat,
        public float $lng,
        public ?int $wmoCode = null,
        public ?float $temperatureC = null,
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
    ) {}

    /**
     * @return WeatherSampleArray
     */
    public function toArray(): array
    {
        return [
            'time_offset_s' => $this->timeOffsetS,
            'distance_m' => $this->distanceM,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'wmo_code' => $this->wmoCode,
            'temperature_c' => $this->temperatureC,
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
        ];
    }
}
