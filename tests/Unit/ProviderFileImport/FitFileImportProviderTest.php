<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Splitline\Core\Data\ActivityStreamData;
use Splitline\Core\Data\ImportedActivityBundle;
use Splitline\Core\Data\NormalizedActivity;
use Splitline\Parsers\Exceptions\ParseException;
use Splitline\ProviderFileImport\FitFileImportProvider;

/**
 * @param  list<string>  $messages
 */
function fileImportBuildFitBinary(array $messages, int $headerSize = 14): string
{
    $payload = implode('', $messages);
    $dataSize = strlen($payload);

    $header = chr($headerSize)
        .chr(0x20)
        .pack('v', 2140)
        .pack('V', $dataSize)
        .'.FIT';

    if ($headerSize === 14) {
        $header .= pack('v', 0);
    }

    return $header.$payload.pack('v', 0);
}

/**
 * @param  list<array{0: int, 1: int, 2: int}>  $fields
 */
function fileImportFitDefinition(
    int $localType,
    int $globalMsg,
    array $fields,
    bool $bigEndian = false,
): string {
    $header = 0x40 | ($localType & 0x0F);

    $bytes = chr($header)
        .chr(0)
        .chr($bigEndian ? 1 : 0)
        .pack($bigEndian ? 'n' : 'v', $globalMsg)
        .chr(count($fields));

    foreach ($fields as [$num, $size, $baseType]) {
        $bytes .= chr($num).chr($size).chr($baseType);
    }

    return $bytes;
}

function fileImportFitData(int $localType, string $payload): string
{
    return chr($localType & 0x0F).$payload;
}

function fileImportDegreesToSemicircles(float $degrees): int
{
    return (int) round($degrees * (2147483648.0 / 180.0));
}

function fileImportZipBytes(string $contents, string $entryName = 'activity.fit'): string
{
    $path = tempnam(sys_get_temp_dir(), 'splitline_provider_zip_');
    expect($path)->toBeString();

    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('README.txt', 'metadata');
    $zip->addFromString($entryName, $contents);
    $zip->close();

    $bytes = file_get_contents($path);
    @unlink($path);

    expect($bytes)->toBeString();

    return $bytes;
}

/**
 * Build a rich synthetic trail running FIT binary with session metadata, FIT summary fields,
 * workout metadata, time-in-zone power bounds, run/walk/idle splits, and 6 GPS+HR records.
 */
function fileImportBuildFullTrailRunFit(): string
{
    // 2026-05-09T20:15:00Z -> unix 1778357700 -> FIT ts = 1778357700 - 631065600 = 1147292100
    // In Pacific/Auckland (UTC+12 in May), local wall clock is 2026-05-10 08:15:00.
    $fitStartTs = 1147292100;
    $messages = [];

    // file_id (MSG 0): manufacturer=1 (Garmin), product=4257 (Forerunner 965)
    $messages[] = fileImportFitDefinition(0, 0, [
        [0, 1, 0x00],
        [1, 2, 0x84],
        [2, 2, 0x84],
    ]);
    $messages[] = fileImportFitData(0, chr(4).pack('v', 1).pack('v', 4257));

    // workout (MSG 26)
    $messages[] = fileImportFitDefinition(1, 26, [
        [8, 12, 0x07],
        [10, 4, 0x86],
        [17, 8, 0x07],
    ]);
    $messages[] = fileImportFitData(
        1,
        str_pad('Trail Tempo', 12, "\0").pack('V', 3600000).str_pad('155bpm', 8, "\0"),
    );

    // time_in_zone (MSG 216) for session (ref_mesg = 18)
    $messages[] = fileImportFitDefinition(2, 216, [
        [0, 1, 0x00],
        [15, 2, 0x84],
        [9, 10, 0x84],
    ]);
    $messages[] = fileImportFitData(2, chr(18).pack('v', 360).pack('v*', 240, 290, 330, 360, 420));

    // record (MSG 20)
    $messages[] = fileImportFitDefinition(3, 20, [
        [253, 4, 0x86], // timestamp
        [0, 4, 0x85],   // lat
        [1, 4, 0x85],   // lng
        [3, 1, 0x02],   // hr
        [4, 1, 0x02],   // cadence (single foot)
        [5, 4, 0x86],   // distance (/100)
        [7, 2, 0x84],   // power
        [13, 1, 0x01],  // temperature
        [73, 4, 0x86],  // enhanced_speed (/1000)
        [78, 4, 0x86],  // enhanced_altitude
        [85, 2, 0x84],  // step_length (/10000 m)
    ]);

    $altitudes = [120.0, 145.0, 180.0, 210.0, 190.0, 165.0];
    $hrs = [142, 150, 158, 164, 160, 152];
    $temps = [12, 13, 14, 14, 15, 16];

    foreach ($altitudes as $i => $alt) {
        $messages[] = fileImportFitData(
            3,
            pack('V', $fitStartTs + ($i * 60))
            .pack('V', fileImportDegreesToSemicircles(-36.8485 + ($i * 0.001)))
            .pack('V', fileImportDegreesToSemicircles(174.7633 + ($i * 0.001)))
            .chr($hrs[$i])
            .chr(86)
            .pack('V', $i * 24000)
            .pack('v', 310 + ($i * 5))
            .chr($temps[$i])
            .pack('V', 4000)
            .pack('V', (int) round(($alt + 500.0) * 5.0))
            .pack('v', 11200), // 1.12m stride length
        );
    }

    // run/walk/idle split summary (MSG 313)
    $messages[] = fileImportFitDefinition(4, 313, [
        [0, 1, 0x00],
        [4, 4, 0x86],
    ]);
    $messages[] = fileImportFitData(4, chr(17).pack('V', 3100000)); // 3100s run
    $messages[] = fileImportFitData(4, chr(18).pack('V', 420000));  // 420s walk
    $messages[] = fileImportFitData(4, chr(28).pack('V', 80000));   // 80s idle

    // session (MSG 18) with standalone metadata + FIT-only summary metrics
    $messages[] = fileImportFitDefinition(5, 18, [
        [2, 4, 0x86],   // start_time
        [5, 1, 0x00],   // sport_id (1 = running)
        [6, 1, 0x00],   // sub_sport_id (3 = trail)
        [7, 4, 0x86],   // total_elapsed_time (/1000)
        [8, 4, 0x86],   // total_timer_time (/1000)
        [9, 4, 0x86],   // total_distance (/100)
        [11, 2, 0x84],  // total_calories
        [16, 1, 0x02],  // avg_hr
        [17, 1, 0x02],  // max_hr
        [18, 1, 0x02],  // avg_cadence
        [19, 1, 0x02],  // max_cadence
        [20, 2, 0x84],  // avg_power
        [21, 2, 0x84],  // max_power
        [22, 2, 0x84],  // total_ascent
        [23, 2, 0x84],  // total_descent
        [48, 4, 0x86],  // total_work
        [58, 1, 0x01],  // max_temperature
        [89, 2, 0x84],  // avg_vertical_oscillation (/100 cm)
        [91, 2, 0x84],  // avg_ground_contact_time (/10 ms)
        [101, 1, 0x02], // avg_left_torque_effectiveness (/2)
        [102, 1, 0x02], // avg_right_torque_effectiveness (/2)
        [103, 1, 0x02], // avg_left_pedal_smoothness (/2)
        [104, 1, 0x02], // avg_right_pedal_smoothness (/2)
        [124, 4, 0x86], // enhanced_avg_speed (/1000)
        [125, 4, 0x86], // enhanced_max_speed (/1000)
        [132, 2, 0x84], // avg_vertical_ratio (/100 %)
        [150, 1, 0x01], // min_temperature
        [169, 2, 0x84], // avg_respiration_rate (/100)
        [170, 2, 0x84], // max_respiration_rate (/100)
        [178, 2, 0x84], // sweat_loss_ml
        [180, 2, 0x84], // min_respiration_rate (/100)
        [185, 1, 0x02], // execution_score
        [192, 1, 0x02], // workout_feel
        [193, 1, 0x02], // workout_rpe
        [196, 2, 0x84], // bmr_calories
        [205, 1, 0x02], // begin_stamina
        [206, 1, 0x02], // end_stamina
        [207, 1, 0x02], // min_stamina
        [211, 4, 0x86], // avg_grade_adjusted_speed (/1000)
        [212, 1, 0x02], // wind_enabled
        [215, 1, 0x02], // begin_body_battery
        [216, 1, 0x02], // end_body_battery
    ]);

    $messages[] = fileImportFitData(
        5,
        pack('V', $fitStartTs)
        .chr(1)
        .chr(3)
        .pack('V', 3680000) // 3680.0s elapsed
        .pack('V', 3600000) // 3600.0s timer
        .pack('V', 1425000) // 14250.0m
        .pack('v', 890)     // calories
        .chr(155)           // avg_hr
        .chr(178)           // max_hr
        .chr(87)            // avg_cadence (87 * 2 = 174.0)
        .chr(93)            // max_cadence (93 * 2 = 186.0)
        .pack('v', 330)     // avg_power
        .pack('v', 490)     // max_power
        .pack('v', 310)     // total_ascent
        .pack('v', 295)     // total_descent
        .pack('V', 1188000) // total_work
        .chr(16)            // max_temperature
        .pack('v', 875)     // 8.75 cm vert osc
        .pack('v', 2650)    // 265.0 ms GCT
        .chr(144)           // 72.0% left TE
        .chr(148)           // 74.0% right TE
        .chr(46)            // 23.0% left PS
        .chr(48)            // 24.0% right PS
        .pack('V', 3958)    // 3.958 m/s avg_speed
        .pack('V', 5400)    // 5.4 m/s max_speed
        .pack('v', 810)     // 8.1% vert ratio
        .chr(12)            // min_temperature
        .pack('v', 3450)    // 34.5 brpm avg resp
        .pack('v', 4420)    // 44.2 brpm max resp
        .pack('v', 1840)    // sweat_loss_ml
        .pack('v', 2210)    // 22.1 brpm min resp
        .chr(92)            // execution_score
        .chr(75)            // workout_feel
        .chr(60)            // workout_rpe
        .pack('v', 95)      // bmr_calories
        .chr(100)           // begin_stamina
        .chr(52)            // end_stamina
        .chr(48)            // min_stamina
        .pack('V', 4120)    // 4.12 m/s GAP speed
        .chr(1)             // wind_enabled
        .chr(82)            // begin_body_battery
        .chr(64),           // end_body_battery
    );

    return fileImportBuildFitBinary($messages);
}

it('reports provider identity, supported extensions, and capabilities', function () {
    $provider = new FitFileImportProvider;
    $capabilities = $provider->capabilities();

    expect($provider->id())->toBe('fit_upload')
        ->and($provider->displayName())->toBe('FIT File Import')
        ->and($provider->supportedExtensions())->toBe(['fit', 'zip'])
        ->and($capabilities->supportsActivities)->toBeTrue()
        ->and($capabilities->allowsGroupSharing)->toBeTrue()
        ->and($capabilities->supportsActivityArchives)->toBeFalse()
        ->and($capabilities->supportsDailyMetrics)->toBeFalse()
        ->and($capabilities->supportsSleep)->toBeFalse()
        ->and($capabilities->supportsHrv)->toBeFalse()
        ->and($capabilities->supportsVo2Max)->toBeFalse()
        ->and($capabilities->supportsFitnessAge)->toBeFalse()
        ->and($capabilities->supportsWeight)->toBeFalse()
        ->and($capabilities->supportsHeartRateZones)->toBeFalse()
        ->and($capabilities->supportsWebhooks)->toBeFalse()
        ->and($capabilities->requiresCommercialLicense)->toBeFalse();
});

it('imports a raw .fit file with metadata, sport classification, timezone conversion, device name, HR coverage, and FIT summary fields', function () {
    $fitBytes = fileImportBuildFullTrailRunFit();
    $provider = new FitFileImportProvider;

    $bundle = $provider->importBytes($fitBytes, 'morning_trail.fit', 'Pacific/Auckland');

    expect($bundle)->toBeInstanceOf(ImportedActivityBundle::class)
        ->and($bundle->format)->toBe('fit')
        ->and($bundle->rawBytes)->toBe($fitBytes)
        ->and($bundle->stream)->toBeInstanceOf(ActivityStreamData::class)
        ->and($bundle->stream->hasHeartRateSamples())->toBeTrue()
        ->and($bundle->stream->heartRateCoverageRatio())->toBe(1.0);

    $activity = $bundle->activity;

    expect($activity)->toBeInstanceOf(NormalizedActivity::class)
        ->and($activity->provider)->toBe('fit_upload')
        ->and($activity->externalId)->toBe(hash('sha256', $fitBytes))
        ->and($activity->name)->toBe('Trail Running 2026-05-10')
        ->and($activity->activityTypeKey)->toBe('trail_running')
        ->and($activity->sportCategory)->toBe('running')
        ->and($activity->sportDiscipline)->toBe('trail')
        ->and($activity->isIndoor)->toBeFalse()
        ->and($activity->deviceName)->toBe('Garmin Forerunner 965')
        ->and($activity->startedAtUtc->toIso8601ZuluString())->toBe('2026-05-09T20:15:00Z')
        ->and($activity->startedAtLocal->timezoneName)->toBe('Pacific/Auckland')
        ->and($activity->startedAtLocal->format('Y-m-d H:i:s'))->toBe('2026-05-10 08:15:00')
        ->and($activity->distanceMeters)->toBe(14250.0)
        ->and($activity->durationSeconds)->toBe(3600.0)
        ->and($activity->movingDurationSeconds)->toBe(3600.0)
        ->and($activity->elapsedDurationSeconds)->toBe(3680.0)
        ->and($activity->elevationGain)->toBe(310.0)
        ->and($activity->elevationLoss)->toBe(295.0)
        ->and($activity->minElevation)->toBe(120.0)
        ->and($activity->maxElevation)->toBe(210.0)
        ->and($activity->startLat)->toBeGreaterThan(-36.8486)->toBeLessThan(-36.8484)
        ->and($activity->startLng)->toBeGreaterThan(174.7632)->toBeLessThan(174.7634)
        ->and($activity->avgHr)->toBe(155)
        ->and($activity->maxHr)->toBe(178)
        ->and($activity->avgSpeed)->toBe(3.958)
        ->and($activity->maxSpeed)->toBe(5.4)
        ->and($activity->calories)->toBe(890)
        ->and($activity->bmrCalories)->toBe(95)
        ->and($activity->sweatLossMl)->toBe(1840)
        ->and($activity->avgPower)->toBe(330.0)
        ->and($activity->maxPower)->toBe(490.0)
        ->and($activity->avgCadence)->toBe(174.0)
        ->and($activity->maxCadence)->toBe(186.0)
        ->and($activity->avgStrideLength)->toBe(1.12)
        ->and($activity->avgVerticalOscillation)->toBe(8.75)
        ->and($activity->avgVerticalRatio)->toBe(8.1)
        ->and($activity->avgGroundContactTime)->toBe(265.0)
        ->and($activity->avgGradeAdjustedSpeed)->toBe(4.12)
        ->and($activity->thresholdPower)->toBe(360.0)
        ->and($activity->powerZoneBounds)->toBe([240, 290, 330, 360, 420])
        ->and($activity->totalWork)->toBe(1188000.0)
        ->and($activity->avgLeftTorqueEffectiveness)->toBe(72.0)
        ->and($activity->avgRightTorqueEffectiveness)->toBe(74.0)
        ->and($activity->avgLeftPedalSmoothness)->toBe(23.0)
        ->and($activity->avgRightPedalSmoothness)->toBe(24.0)
        ->and($activity->windEnabled)->toBeTrue()
        ->and($activity->beginBodyBattery)->toBe(82)
        ->and($activity->endBodyBattery)->toBe(64)
        ->and($activity->bodyBatteryDiff)->toBe(-18)
        ->and($activity->beginStamina)->toBe(100)
        ->and($activity->endStamina)->toBe(52)
        ->and($activity->minStamina)->toBe(48)
        ->and($activity->workoutFeel)->toBe(75)
        ->and($activity->workoutRpe)->toBe(60)
        ->and($activity->executionScore)->toBe(92)
        ->and($activity->workoutMeta)->toMatchArray([
            'name' => 'Trail Tempo',
            'target_label' => '155bpm',
            'duration_seconds' => 3600.0,
        ])
        ->and($activity->avgTemperature)->toBe(14.0)
        ->and($activity->minTemperature)->toBe(12.0)
        ->and($activity->maxTemperature)->toBe(16.0)
        ->and($activity->avgRespirationRate)->toBe(34.5)
        ->and($activity->minRespirationRate)->toBe(22.1)
        ->and($activity->maxRespirationRate)->toBe(44.2)
        ->and($activity->runDurationSeconds)->toBe(3100.0)
        ->and($activity->walkDurationSeconds)->toBe(420.0)
        ->and($activity->idleDurationSeconds)->toBe(80.0);
});

it('produces the exact same externalId when importing a .zip wrapping the same .fit bytes', function () {
    $fitBytes = fileImportBuildFullTrailRunFit();
    $zipBytes = fileImportZipBytes($fitBytes, '123456_ACTIVITY.FIT');
    $provider = new FitFileImportProvider;

    $rawBundle = $provider->importBytes($fitBytes, 'activity.fit', 'Pacific/Auckland');
    $zipBundle = $provider->importBytes($zipBytes, 'activity.zip', 'Pacific/Auckland');
    $magicZipBundle = $provider->importBytes($zipBytes, 'uploaded_blob.bin', 'Pacific/Auckland');

    expect($zipBundle->format)->toBe('fit_zip')
        ->and($zipBundle->rawBytes)->toBe($zipBytes)
        ->and($zipBundle->activity->externalId)->toBe($rawBundle->activity->externalId)
        ->and($zipBundle->activity->externalId)->toBe(hash('sha256', $fitBytes))
        ->and($zipBundle->activity->distanceMeters)->toBe($rawBundle->activity->distanceMeters)
        ->and($zipBundle->activity->name)->toBe($rawBundle->activity->name)
        ->and($magicZipBundle->format)->toBe('fit_zip')
        ->and($magicZipBundle->activity->externalId)->toBe($rawBundle->activity->externalId);
});

it('computes fallback metrics from records when session message omits summary metrics', function () {
    // 2026-05-10T10:00:00Z -> unix 1778407200 -> FIT ts = 1147341600
    $fitStartTs = 1147341600;
    $messages = [];

    // file_id: manufacturer=32 (Wahoo Fitness)
    $messages[] = fileImportFitDefinition(0, 0, [
        [0, 1, 0x00],
        [1, 2, 0x84],
    ]);
    $messages[] = fileImportFitData(0, chr(4).pack('v', 32));

    // Minimal session: only sport=2 (cycling), sub_sport=7 (road_biking), start_time
    $messages[] = fileImportFitDefinition(1, 18, [
        [2, 4, 0x86],
        [5, 1, 0x00],
        [6, 1, 0x00],
    ]);
    $messages[] = fileImportFitData(1, pack('V', $fitStartTs).chr(2).chr(7));

    // Records with distance, altitude, speed, HR, cadence, power, GPS, pedaling dynamics, flow
    $messages[] = fileImportFitDefinition(2, 20, [
        [253, 4, 0x86], // timestamp
        [0, 4, 0x85],   // lat
        [1, 4, 0x85],   // lng
        [3, 1, 0x02],   // hr
        [4, 1, 0x02],   // cadence (undoubled cycling)
        [5, 4, 0x86],   // distance (/100)
        [7, 2, 0x84],   // power
        [30, 1, 0x02],  // left_right_balance
        [43, 1, 0x02],  // left_te (/2)
        [44, 1, 0x02],  // right_te (/2)
        [45, 1, 0x02],  // left_ps (/2)
        [46, 1, 0x02],  // right_ps (/2)
        [73, 4, 0x86],  // enhanced_speed (/1000)
        [78, 4, 0x86],  // enhanced_altitude
        [115, 4, 0x88], // flow (float32)
    ]);

    // Record 0: t=0s, dist=0m, alt=100m, hr=130, cad=80, pwr=200, spd=8.0
    $messages[] = fileImportFitData(
        2,
        pack('V', $fitStartTs)
        .pack('V', fileImportDegreesToSemicircles(-43.5321))
        .pack('V', fileImportDegreesToSemicircles(172.6362))
        .chr(130)
        .chr(80)
        .pack('V', 0)
        .pack('v', 200)
        .chr(0x80 | 51) // 51% right -> 49% left
        .chr(140)       // 70%
        .chr(144)       // 72%
        .chr(40)        // 20%
        .chr(44)        // 22%
        .pack('V', 8000)
        .pack('V', (int) round((100.0 + 500.0) * 5.0))
        .pack('g', 2.5),
    );

    // Record 1: t=120s, dist=1000m, alt=150m (+50m), hr=150, cad=90, pwr=260, spd=9.0
    $messages[] = fileImportFitData(
        2,
        pack('V', $fitStartTs + 120)
        .pack('V', fileImportDegreesToSemicircles(-43.5250))
        .pack('V', fileImportDegreesToSemicircles(172.6400))
        .chr(150)
        .chr(90)
        .pack('V', 100000)
        .pack('v', 260)
        .chr(0x80 | 49) // 49% right -> 51% left (avg = 50.0%)
        .chr(160)       // 80% (avg = 75.0%)
        .chr(156)       // 78% (avg = 75.0%)
        .chr(48)        // 24% (avg = 22.0%)
        .chr(52)        // 26% (avg = 24.0%)
        .pack('V', 9000)
        .pack('V', (int) round((150.0 + 500.0) * 5.0))
        .pack('g', 3.5),
    );

    // Record 2: t=300s, dist=2500m, alt=125m (-25m), hr=140, cad=85, pwr=230, spd=10.0
    $messages[] = fileImportFitData(
        2,
        pack('V', $fitStartTs + 300)
        .pack('V', fileImportDegreesToSemicircles(-43.5150))
        .pack('V', fileImportDegreesToSemicircles(172.6450))
        .chr(140)
        .chr(85)
        .pack('V', 250000)
        .pack('v', 230)
        .chr(0x80 | 50) // 50% left
        .chr(150)       // 75%
        .chr(150)       // 75%
        .chr(44)        // 22%
        .chr(48)        // 24%
        .pack('V', 10000)
        .pack('V', (int) round((125.0 + 500.0) * 5.0))
        .pack('g', 3.0),
    );

    // Jump event (MSG 285)
    $messages[] = fileImportFitDefinition(3, 285, [
        [253, 4, 0x86],
        [0, 4, 0x88],
        [3, 4, 0x88],
        [4, 4, 0x88],
    ]);
    $messages[] = fileImportFitData(
        3,
        pack('V', $fitStartTs + 180)
        .pack('g', 4.2)
        .pack('g', 0.45)
        .pack('g', 82.0),
    );

    $provider = new FitFileImportProvider;
    $bundle = $provider->importBytes(fileImportBuildFitBinary($messages), 'ride.fit', 'Not/A_Timezone');
    $activity = $bundle->activity;

    expect($activity->name)->toBe('Road Biking 2026-05-10')
        ->and($activity->activityTypeKey)->toBe('road_biking')
        ->and($activity->sportCategory)->toBe('biking')
        ->and($activity->sportDiscipline)->toBe('road')
        ->and($activity->isIndoor)->toBeFalse()
        ->and($activity->deviceName)->toBe('Wahoo Fitness')
        ->and($activity->startedAtLocal->timezoneName)->toBe('UTC')
        ->and($activity->distanceMeters)->toBe(2500.0)
        ->and($activity->durationSeconds)->toBe(300.0)
        ->and($activity->elapsedDurationSeconds)->toBe(300.0)
        ->and($activity->movingDurationSeconds)->toBeNull()
        ->and($activity->elevationGain)->toBe(50.0)
        ->and($activity->elevationLoss)->toBe(25.0)
        ->and($activity->minElevation)->toBe(100.0)
        ->and($activity->maxElevation)->toBe(150.0)
        ->and($activity->startLat)->toBeGreaterThan(-43.5322)->toBeLessThan(-43.5320)
        ->and($activity->startLng)->toBeGreaterThan(172.6361)->toBeLessThan(172.6363)
        ->and($activity->avgHr)->toBe(140)
        ->and($activity->maxHr)->toBe(150)
        ->and($activity->avgSpeed)->toBe(9.0)
        ->and($activity->maxSpeed)->toBe(10.0)
        ->and($activity->avgPower)->toBe(230.0)
        ->and($activity->maxPower)->toBe(260.0)
        ->and($activity->avgCadence)->toBe(85.0)
        ->and($activity->maxCadence)->toBe(90.0)
        ->and($activity->leftBalance)->toBe(50.0)
        ->and($activity->avgLeftTorqueEffectiveness)->toBe(75.0)
        ->and($activity->avgRightTorqueEffectiveness)->toBe(75.0)
        ->and($activity->avgLeftPedalSmoothness)->toBe(22.0)
        ->and($activity->avgRightPedalSmoothness)->toBe(24.0)
        ->and($activity->avgFlow)->toBe(3.0)
        ->and($activity->jumpCount)->toBe(1);
});

it('handles unknown manufacturer and missing start timestamp gracefully', function () {
    $messages = [
        fileImportFitDefinition(0, 0, [
            [1, 2, 0x84], // unknown manufacturer 9999
        ]),
        fileImportFitData(0, pack('v', 9999)),
    ];

    $before = CarbonImmutable::now('UTC')->subSecond();
    $bundle = (new FitFileImportProvider)->importBytes(fileImportBuildFitBinary($messages), 'minimal.fit');
    $after = CarbonImmutable::now('UTC')->addSecond();

    expect($bundle->activity->deviceName)->toBeNull()
        ->and($bundle->activity->activityTypeKey)->toBe('other')
        ->and($bundle->activity->sportCategory)->toBe('other')
        ->and($bundle->activity->distanceMeters)->toBe(0.0)
        ->and($bundle->activity->durationSeconds)->toBe(0.0)
        ->and($bundle->activity->startedAtUtc->betweenIncluded($before, $after))->toBeTrue();
});

it('throws ParseException for corrupt .fit files and ZIP archives without a .fit entry', function () {
    $provider = new FitFileImportProvider;

    expect(fn () => $provider->importBytes('corrupt-binary-data', 'broken.fit'))
        ->toThrow(ParseException::class);

    expect(fn () => $provider->importBytes('not-a-valid-zip', 'broken.zip'))
        ->toThrow(ParseException::class, 'Unable to open FIT ZIP archive');

    $zipWithoutFit = fileImportZipBytes('plain text only', 'notes.txt');

    expect(fn () => $provider->importBytes($zipWithoutFit, 'empty.zip'))
        ->toThrow(ParseException::class, 'No .fit file found');
});
