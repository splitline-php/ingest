<?php

declare(strict_types=1);

use Splitline\Core\Analytics\ActivityClassifier;

it('classifies provider activity types into sport categories, sport disciplines, and indoor flags', function (
    ?string $typeKey,
    string $expectedCategory,
    ?string $expectedDiscipline,
    bool $expectedIndoor,
): void {
    $classifier = new ActivityClassifier;

    expect($classifier->classify($typeKey))->toBe([
        'sport_category' => $expectedCategory,
        'sport_discipline' => $expectedDiscipline,
        'is_indoor' => $expectedIndoor,
    ]);
})->with([
    'running' => ['running', 'running', 'road', false],
    'trail running' => ['trail_running', 'running', 'trail', false],
    'track running' => ['track_running', 'running', 'track', false],
    'treadmill running' => ['treadmill_running', 'running', 'road', true],
    'road biking' => ['road_biking', 'biking', 'road', false],
    'gravel cycling' => ['gravel_cycling', 'biking', 'gravel', false],
    'indoor cycling' => ['indoor_cycling', 'biking', 'road', true],
    'virtual ride' => ['virtual_ride', 'biking', 'road', true],
    'mountain biking' => ['mountain_biking', 'biking', 'mtb', false],
    'e-bike mountain' => ['e_bike_mountain', 'biking', 'ebike', false],
    'commuting' => ['commuting', 'biking', 'commute', false],
    'generic cycling' => ['cycling', 'biking', 'other', false],
    'walking' => ['walking', 'walking', null, false],
    'hiking' => ['hiking', 'hiking', null, false],
    'lap swimming' => ['lap_swimming', 'swimming', null, true],
    'open water swimming' => ['open_water_swimming', 'swimming', null, false],
    'strength training' => ['strength_training', 'cardio', null, true],
    'unknown sport' => ['stand_up_paddleboarding', 'other', null, false],
    'null sport' => [null, 'other', null, false],
    'empty sport' => ['', 'other', null, false],
    'trimmed mixed-case running' => ['  Running  ', 'running', 'road', false],
    'unknown indoor sport' => ['indoor_climbing', 'other', null, true],
]);

it('classifies every mapped FIT sport and sub-sport pair and falls back to other when unmapped', function (
    ?int $fitSport,
    ?int $fitSubSport,
    string $expectedTypeKey,
    string $expectedCategory,
    ?string $expectedDiscipline,
    bool $expectedIndoor,
): void {
    $classifier = new ActivityClassifier;

    expect($classifier->classifyFitSport($fitSport, $fitSubSport))->toBe([
        'activity_type_key' => $expectedTypeKey,
        'sport_category' => $expectedCategory,
        'sport_discipline' => $expectedDiscipline,
        'is_indoor' => $expectedIndoor,
    ]);
})->with([
    'running: treadmill (1, 1)' => [1, 1, 'treadmill_running', 'running', 'road', true],
    'running: street (1, 2)' => [1, 2, 'street_running', 'running', 'road', false],
    'running: trail (1, 3)' => [1, 3, 'trail_running', 'running', 'trail', false],
    'running: track (1, 4)' => [1, 4, 'track_running', 'running', 'track', false],
    'running: indoor (1, 45)' => [1, 45, 'indoor_running', 'running', 'road', true],
    'running: virtual (1, 58)' => [1, 58, 'virtual_run', 'running', 'road', true],
    'running: generic sub-sport (1, 0)' => [1, 0, 'running', 'running', 'road', false],
    'running: null sub-sport (1, null)' => [1, null, 'running', 'running', 'road', false],
    'cycling: spin (2, 5)' => [2, 5, 'indoor_cycling', 'biking', 'road', true],
    'cycling: indoor (2, 6)' => [2, 6, 'indoor_cycling', 'biking', 'road', true],
    'cycling: road (2, 7)' => [2, 7, 'road_biking', 'biking', 'road', false],
    'cycling: mountain (2, 8)' => [2, 8, 'mountain_biking', 'biking', 'mtb', false],
    'cycling: downhill (2, 9)' => [2, 9, 'downhill_biking', 'biking', 'mtb', false],
    'cycling: recumbent (2, 10)' => [2, 10, 'recumbent_cycling', 'biking', 'road', false],
    'cycling: cyclocross (2, 11)' => [2, 11, 'cyclocross', 'biking', 'gravel', false],
    'cycling: track (2, 13)' => [2, 13, 'track_cycling', 'biking', 'road', false],
    'cycling: e-bike fitness (2, 28)' => [2, 28, 'e_bike_fitness', 'biking', 'ebike', false],
    'cycling: gravel 46 (2, 46)' => [2, 46, 'gravel_cycling', 'biking', 'gravel', false],
    'cycling: gravel 49 (2, 49)' => [2, 49, 'gravel_cycling', 'biking', 'gravel', false],
    'cycling: e-bike mountain (2, 47)' => [2, 47, 'e_bike_mountain', 'biking', 'ebike', false],
    'cycling: commuting (2, 48)' => [2, 48, 'commuting', 'biking', 'commute', false],
    'cycling: virtual ride (2, 58)' => [2, 58, 'virtual_ride', 'biking', 'road', true],
    'cycling: generic sub-sport (2, 0)' => [2, 0, 'cycling', 'biking', 'other', false],
    'cycling: null sub-sport (2, null)' => [2, null, 'cycling', 'biking', 'other', false],
    'swimming: lap (5, 17)' => [5, 17, 'lap_swimming', 'swimming', null, true],
    'swimming: open water (5, 18)' => [5, 18, 'open_water_swimming', 'swimming', null, false],
    'swimming: generic (5, 0)' => [5, 0, 'swimming', 'swimming', null, false],
    'swimming: null sub-sport (5, null)' => [5, null, 'swimming', 'swimming', null, false],
    'training: strength (10, 20)' => [10, 20, 'strength_training', 'cardio', null, true],
    'training: indoor cardio (10, 26)' => [10, 26, 'indoor_cardio', 'cardio', null, true],
    'training: generic cardio (10, 0)' => [10, 0, 'cardio', 'cardio', null, false],
    'training: null sub-sport (10, null)' => [10, null, 'cardio', 'cardio', null, false],
    'walking (11, null)' => [11, null, 'walking', 'walking', null, false],
    'cross country skiing (12, null)' => [12, null, 'cross_country_skiing', 'other', null, false],
    'resort skiing (13, null)' => [13, null, 'resort_skiing', 'other', null, false],
    'rowing: indoor (15, 14)' => [15, 14, 'indoor_rowing', 'cardio', null, true],
    'rowing: outdoor (15, 0)' => [15, 0, 'rowing', 'cardio', null, false],
    'rowing: null sub-sport (15, null)' => [15, null, 'rowing', 'cardio', null, false],
    'hiking (17, null)' => [17, null, 'hiking', 'hiking', null, false],
    'e-biking: mountain 8 (21, 8)' => [21, 8, 'e_bike_mountain', 'biking', 'ebike', false],
    'e-biking: mountain 47 (21, 47)' => [21, 47, 'e_bike_mountain', 'biking', 'ebike', false],
    'e-biking: fitness default (21, 0)' => [21, 0, 'e_bike_fitness', 'biking', 'ebike', false],
    'e-biking: null sub-sport (21, null)' => [21, null, 'e_bike_fitness', 'biking', 'ebike', false],
    'unmapped sport (999, 0)' => [999, 0, 'other', 'other', null, false],
    'null sport (null, null)' => [null, null, 'other', 'other', null, false],
]);
