<?php

declare(strict_types=1);

namespace Splitline\Core\Data;

final readonly class FitnessAgeData
{
    /**
     * @param  array<int|string, mixed>|null  $components
     */
    public function __construct(
        public string $provider,
        public string $calendarDate,
        public ?float $chronologicalAge = null,
        public ?float $fitnessAge = null,
        public ?float $previousFitnessAge = null,
        public ?array $components = null,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     calendar_date: string,
     *     chronological_age: ?float,
     *     fitness_age: ?float,
     *     previous_fitness_age: ?float,
     *     components: array<int|string, mixed>|null
     * }
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'calendar_date' => $this->calendarDate,
            'chronological_age' => $this->chronologicalAge,
            'fitness_age' => $this->fitnessAge,
            'previous_fitness_age' => $this->previousFitnessAge,
            'components' => $this->components,
        ];
    }
}
