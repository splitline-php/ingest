<?php

declare(strict_types=1);

use Splitline\Core\Data\ActivityStreamData;
use Splitline\Parsers\Exceptions\ParseException;
use Splitline\Parsers\Fit\FitParser;

/**
 * Helper to build a real binary FIT v2 (14-byte header) or v1 (12-byte header) stream.
 *
 * @param  list<string>  $messages
 */
function buildFitBinary(array $messages, int $headerSize = 14): string
{
    $payload = implode('', $messages);
    $dataSize = strlen($payload);

    $header = chr($headerSize)
        .chr(0x20) // protocol version 2.0
        .pack('v', 2140) // profile version
        .pack('V', $dataSize)
        .'.FIT';

    if ($headerSize === 14) {
        $header .= pack('v', 0); // header CRC
    }

    return $header.$payload.pack('v', 0); // trailing CRC
}

/**
 * Build a FIT definition message.
 *
 * @param  list<array{0: int, 1: int, 2: int}>  $fields  [fieldNum, size, baseType]
 * @param  list<array{0: int, 1: int, 2: int}>  $devFields  [fieldNum, size, devIndex]
 */
function fitDefinition(
    int $localType,
    int $globalMsg,
    array $fields,
    bool $bigEndian = false,
    array $devFields = [],
): string {
    $hasDev = $devFields !== [];
    $header = 0x40 | ($hasDev ? 0x20 : 0x00) | ($localType & 0x0F);

    $bytes = chr($header)
        .chr(0) // reserved
        .chr($bigEndian ? 1 : 0)
        .pack($bigEndian ? 'n' : 'v', $globalMsg)
        .chr(count($fields));

    foreach ($fields as [$num, $size, $baseType]) {
        $bytes .= chr($num).chr($size).chr($baseType);
    }

    if ($hasDev) {
        $bytes .= chr(count($devFields));

        foreach ($devFields as [$num, $size, $devIdx]) {
            $bytes .= chr($num).chr($size).chr($devIdx);
        }
    }

    return $bytes;
}

function fitData(int $localType, string $payload): string
{
    return chr($localType & 0x0F).$payload;
}

function fitCompressedData(int $localType, int $timeOffset, string $payload): string
{
    $header = 0x80 | (($localType & 0x03) << 5) | ($timeOffset & 0x1F);

    return chr($header).$payload;
}

function degreesToSemicircles(float $degrees): int
{
    return (int) round($degrees * (2147483648.0 / 180.0));
}

function zipFitBytes(string $fitBytes, string $filename = '123456_ACTIVITY.fit'): string
{
    $path = tempnam(sys_get_temp_dir(), 'fit_test_zip_');
    expect($path)->toBeString();

    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('README.txt', 'ignored');
    $zip->addFromString($filename, $fitBytes);
    $zip->close();

    $bytes = file_get_contents($path);
    @unlink($path);

    expect($bytes)->toBeString();

    return $bytes;
}

it('extracts the first .fit file entry from raw ZIP bytes', function () {
    $fitBytes = buildFitBinary([
        fitDefinition(0, 0, [[0, 1, 0x00], [1, 2, 0x84]]),
        fitData(0, chr(4).pack('v', 1)),
    ]);

    $zipBytes = zipFitBytes($fitBytes, 'ACTIVITY.FIT');
    $extracted = (new FitParser)->extractFitFromZip($zipBytes);

    expect($extracted)->toBe($fitBytes);
});

it('parses a zipped FIT file with records, laps, enhanced fields, invalid sentinels, and developer fields', function () {
    $baseFitTs = 1100000000;
    $messages = [];

    // file_id (global 0)
    $messages[] = fitDefinition(0, 0, [
        [0, 1, 0x00], // type (enum)
        [1, 2, 0x84], // manufacturer (uint16)
    ]);
    $messages[] = fitData(0, chr(4).pack('v', 1));

    // session (global 18) in big-endian to verify endianness support
    $messages[] = fitDefinition(1, 18, [
        [5, 1, 0x00], // sport
        [9, 4, 0x86], // total_distance
    ], bigEndian: true);
    $messages[] = fitData(1, chr(1).pack('N', 100000));

    // lap (global 19) with enhanced_avg_speed
    $messages[] = fitDefinition(2, 19, [
        [2, 4, 0x86],   // start_time
        [7, 4, 0x86],   // total_elapsed_time (/1000)
        [8, 4, 0x86],   // total_timer_time (/1000)
        [9, 4, 0x86],   // total_distance (/100)
        [13, 2, 0x84],  // avg_speed (/1000)
        [15, 1, 0x02],  // avg_hr
        [16, 1, 0x02],  // max_hr
        [17, 1, 0x02],  // avg_cadence
        [19, 2, 0x84],  // avg_power
        [21, 2, 0x84],  // total_ascent
        [22, 2, 0x84],  // total_descent
        [110, 4, 0x86], // enhanced_avg_speed (/1000)
    ]);
    $messages[] = fitData(2,
        pack('V', $baseFitTs)
        .pack('V', 260000) // 260.0s elapsed
        .pack('V', 250000) // 250.0s timer
        .pack('V', 100000) // 1000.0m
        .pack('v', 3900)   // legacy 3.9 m/s (overridden by enhanced)
        .chr(158)          // avg_hr
        .chr(172)          // max_hr
        .chr(176)          // avg_cadence
        .pack('v', 310)    // avg_power
        .pack('v', 24)     // ascent
        .pack('v', 18)     // descent
        .pack('V', 4000),  // enhanced 4.0 m/s
    );

    // record (global 20) WITH 2 Connect IQ developer fields (6 bytes total) to verify zero offset desync
    $messages[] = fitDefinition(
        localType: 3,
        globalMsg: 20,
        fields: [
            [253, 4, 0x86], // timestamp
            [0, 4, 0x85],   // position_lat (sint32)
            [1, 4, 0x85],   // position_long (sint32)
            [2, 2, 0x84],   // altitude (legacy)
            [3, 1, 0x02],   // heart_rate
            [4, 1, 0x02],   // cadence
            [5, 4, 0x86],   // distance
            [6, 2, 0x84],   // speed (legacy)
            [7, 2, 0x84],   // power
            [73, 4, 0x86],  // enhanced_speed
            [78, 4, 0x86],  // enhanced_altitude
        ],
        devFields: [
            [0, 2, 0], // custom 2-byte IQ field
            [1, 4, 0], // custom 4-byte IQ field
        ],
    );

    // Record 0: enhanced altitude (100m -> (100+500)*5 = 3000) & enhanced speed (4.25 m/s -> 4250)
    $messages[] = fitData(3,
        pack('V', $baseFitTs)
        .pack('V', degreesToSemicircles(-36.8485))
        .pack('V', degreesToSemicircles(174.7633))
        .pack('v', 2800)   // legacy altitude 60m (should be overridden by enhanced)
        .chr(145)
        .chr(170)
        .pack('V', 0)
        .pack('v', 3800)   // legacy speed 3.8 m/s (should be overridden by enhanced)
        .pack('v', 295)
        .pack('V', 4250)   // enhanced speed 4.25 m/s
        .pack('V', 3000)   // enhanced altitude 100.0m
        .pack('v', 999)    // dev field 0
        .pack('V', 123456), // dev field 1
    );

    // Record 1: invalid sentinels for enhanced fields, HR, and power -> falls back to legacy altitude/speed & nulls
    $messages[] = fitData(3,
        pack('V', $baseFitTs + 10)
        .pack('V', degreesToSemicircles(-36.8481))
        .pack('V', degreesToSemicircles(174.7635))
        .pack('v', 3050)       // legacy altitude 110.0m
        .chr(0xFF)             // invalid HR sentinel
        .chr(174)
        .pack('V', 4250)       // 42.5m
        .pack('v', 4100)       // legacy speed 4.1 m/s
        .pack('v', 0xFFFF)     // invalid power sentinel
        .pack('V', 0xFFFFFFFF) // invalid enhanced speed sentinel
        .pack('V', 0xFFFFFFFF) // invalid enhanced altitude sentinel
        .pack('v', 888)
        .pack('V', 654321),
    );

    $zipBytes = zipFitBytes(buildFitBinary($messages));
    $parsed = (new FitParser)->parseZip($zipBytes);

    expect($parsed)->toBeInstanceOf(ActivityStreamData::class)
        ->and($parsed->records)->toHaveCount(2);

    expect($parsed->records[0])->toMatchArray([
        'time' => 0.0,
        'distance' => 0.0,
        'altitude' => 100.0,
        'speed' => 4.25,
        'hr' => 145,
        'cadence' => 170,
        'power' => 295,
    ]);
    expect($parsed->records[0]['lat'])->toBeGreaterThan(-36.8486)->toBeLessThan(-36.8484);
    expect($parsed->records[0]['lng'])->toBeGreaterThan(174.7632)->toBeLessThan(174.7634);

    expect($parsed->records[1])->toMatchArray([
        'time' => 10.0,
        'distance' => 42.5,
        'altitude' => 110.0,
        'speed' => 4.1,
        'hr' => null,
        'cadence' => 174,
        'power' => null,
    ]);

    expect($parsed->laps)->toHaveCount(1)
        ->and($parsed->laps[0])->toBe([
            'lap' => 1,
            'distance_meters' => 1000.0,
            'duration_seconds' => 250.0,
            'elapsed_seconds' => 260.0,
            'avg_speed' => 4.0,
            'gap_seconds_per_km' => null,
            'avg_hr' => 158,
            'max_hr' => 172,
            'avg_cadence' => 176,
            'avg_power' => 310,
            'elevation_gain' => 24.0,
            'elevation_loss' => 18.0,
        ])
        ->and($parsed->sessionMetadata)->toBe([
            'sport_id' => 1,
            'total_distance' => 1000.0,
            'manufacturer_id' => 1,
        ]);
});

it('decodes compressed timestamp header records including 5-bit rollover', function () {
    // Choose a base timestamp whose lower 5 bits are 30 (0x1E) to test rollover past 31
    $baseFitTs = (1100000000 & ~0x1F) + 30;
    $messages = [];

    // Normal record with explicit timestamp (local type 0)
    $messages[] = fitDefinition(0, 20, [
        [253, 4, 0x86],
        [5, 4, 0x86],
    ]);
    // Compressed record without explicit timestamp field (local type 1)
    $messages[] = fitDefinition(1, 20, [
        [5, 4, 0x86],
    ]);

    $messages[] = fitData(0, pack('V', $baseFitTs).pack('V', 0));
    // offset = 31 (>= 30) => +1s
    $messages[] = fitCompressedData(1, 31, pack('V', 400));
    // offset = 2 (< 31 => rollover +32) => +3s from previous (+4s total)
    $messages[] = fitCompressedData(1, 2, pack('V', 1600));
    // offset = 12 (>= 2) => +10s from previous (+14s total)
    $messages[] = fitCompressedData(1, 12, pack('V', 5600));

    $parsed = (new FitParser)->parseBinary(buildFitBinary($messages, headerSize: 12));

    expect(array_column($parsed->records, 'time'))->toBe([0.0, 1.0, 4.0, 14.0])
        ->and(array_column($parsed->records, 'distance'))->toBe([0.0, 4.0, 16.0, 56.0]);
});

it('computes cumulative Haversine distance when FIT records omit distance', function () {
    $baseFitTs = 1100000000;
    $messages = [];

    $messages[] = fitDefinition(0, 20, [
        [253, 4, 0x86],
        [0, 4, 0x85],
        [1, 4, 0x85],
    ]);

    // Move north along meridian by 0.001 degrees (~111.2m per step)
    for ($i = 0; $i < 4; $i++) {
        $messages[] = fitData(0,
            pack('V', $baseFitTs + ($i * 30))
            .pack('V', degreesToSemicircles(-36.8500 + ($i * 0.001)))
            .pack('V', degreesToSemicircles(174.7600)),
        );
    }

    $parsed = (new FitParser)->parseBinary(buildFitBinary($messages));

    expect($parsed->records[0]['distance'])->toBe(0.0)
        ->and($parsed->records[1]['distance'])->toBeGreaterThan(105.0)->toBeLessThan(118.0)
        ->and($parsed->records[3]['distance'])->toBeGreaterThan(325.0)->toBeLessThan(345.0);
});

it('downsamples series to <= 600 points, simplifies polyline to <= 400 points, and computes 1 km splits with Minetti GAP', function () {
    $baseFitTs = 1100000000;
    $messages = [];

    $messages[] = fitDefinition(0, 20, [
        [253, 4, 0x86], // timestamp
        [0, 4, 0x85],   // lat
        [1, 4, 0x85],   // lng
        [3, 1, 0x02],   // hr
        [4, 1, 0x02],   // cadence
        [5, 4, 0x86],   // distance
        [7, 2, 0x84],   // power
        [73, 4, 0x86],  // enhanced_speed
        [78, 4, 0x86],  // enhanced_altitude
    ]);

    // 750 seconds @ 4.0 m/s = 3,000 meters:
    // km 1 (0..1000m): flat (altitude 100m)
    // km 2 (1000..2000m): +6% uphill (+60m over 1000m -> 100m..160m)
    // km 3 (2000..3000m): -6% downhill (-60m over 1000m -> 160m..100m)
    for ($sec = 0; $sec <= 750; $sec++) {
        $distance = $sec * 4.0;

        if ($distance <= 1000.0) {
            $altitude = 100.0;
        } elseif ($distance <= 2000.0) {
            $altitude = 100.0 + (($distance - 1000.0) * 0.06);
        } else {
            $altitude = 160.0 - (($distance - 2000.0) * 0.06);
        }

        // Introduce gentle zigzag so polyline has > 400 raw GNSS points
        $lat = -36.8500 + ($sec * 0.00004);
        $lng = 174.7600 + (sin($sec / 5.0) * 0.0005);

        $messages[] = fitData(0,
            pack('V', $baseFitTs + $sec)
            .pack('V', degreesToSemicircles($lat))
            .pack('V', degreesToSemicircles($lng))
            .chr(155)
            .chr(178)
            .pack('V', (int) round($distance * 100))
            .pack('v', 300)
            .pack('V', 4000)
            .pack('V', (int) round(($altitude + 500.0) * 5.0)),
        );
    }

    $parsed = (new FitParser)->parseBinary(buildFitBinary($messages));

    expect($parsed->records)->toHaveCount(751)
        ->and(count($parsed->series))->toBeLessThanOrEqual(600)
        ->and($parsed->series[0]['time'])->toBe(0.0)
        ->and($parsed->series[count($parsed->series) - 1]['time'])->toBe(750.0)
        ->and(count($parsed->polyline))->toBeGreaterThan(2)->toBeLessThanOrEqual(400);

    // Check smoothed grade in uphill section (~6%)
    $uphillPoint = array_find(
        $parsed->series,
        static fn (array $pt): bool => ($pt['distance'] ?? 0.0) >= 1400.0 && ($pt['distance'] ?? 0.0) <= 1600.0,
    );
    expect($uphillPoint)->not->toBeNull()
        ->and($uphillPoint['grade'])->toBeGreaterThan(5.0)->toBeLessThan(7.0);

    expect($parsed->kmSplits)->toHaveCount(3);

    [$km1, $km2, $km3] = $parsed->kmSplits;

    // km 1: flat -> GAP equals actual pace (250 s/km)
    expect($km1['km'])->toBe(1)
        ->and($km1['distance_meters'])->toBe(1000.0)
        ->and($km1['duration_seconds'])->toBe(250.0)
        ->and($km1['pace_seconds_per_km'])->toBe(250.0)
        ->and($km1['gap_seconds_per_km'])->toBe(250.0)
        ->and($km1['avg_grade'])->toBe(0.0)
        ->and($km1['avg_hr'])->toBe(155)
        ->and($km1['avg_cadence'])->toBe(178)
        ->and($km1['avg_power'])->toBe(300);

    // km 2: +6% uphill -> GAP is faster (lower s/km) than 250 s/km
    expect($km2['km'])->toBe(2)
        ->and($km2['elevation_gain'])->toBe(60.0)
        ->and($km2['elevation_loss'])->toBe(0.0)
        ->and($km2['avg_grade'])->toBe(6.0)
        ->and($km2['gap_seconds_per_km'])->toBeLessThan(200.0)->toBeGreaterThan(170.0);

    // km 3: -6% downhill -> GAP is slower (higher s/km) than 250 s/km
    expect($km3['km'])->toBe(3)
        ->and($km3['elevation_gain'])->toBe(0.0)
        ->and($km3['elevation_loss'])->toBe(60.0)
        ->and($km3['avg_grade'])->toBe(-6.0)
        ->and($km3['gap_seconds_per_km'])->toBeGreaterThan(300.0)->toBeLessThan(380.0);
});

it('rejects malformed FIT headers and ZIP archives without a .fit entry', function () {
    $parser = new FitParser;

    expect(fn () => $parser->parseBinary('not-a-fit-file'))
        ->toThrow(ParseException::class);

    expect(fn () => $parser->extractFitFromZip('not-a-zip-archive'))
        ->toThrow(ParseException::class, 'Unable to open FIT ZIP archive');

    $path = tempnam(sys_get_temp_dir(), 'no_fit_zip_');
    expect($path)->toBeString();
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('notes.txt', 'hello');
    $zip->close();
    $zipBytes = (string) file_get_contents($path);
    @unlink($path);

    expect(fn () => $parser->extractFitFromZip($zipBytes))
        ->toThrow(ParseException::class, 'No .fit file found');

    expect(fn () => $parser->parseZip($zipBytes))
        ->toThrow(ParseException::class, 'No .fit file found');
});

it('extracts running dynamics, stamina, performance condition, doubled cadence, native GAP, run/walk splits, and workout metadata', function () {
    $baseFitTs = 1163947421;
    $messages = [];

    // workout (MSG 26)
    $messages[] = fitDefinition(0, 26, [
        [8, 10, 0x07],  // workout_name
        [10, 4, 0x86],  // duration ms
        [17, 8, 0x07],  // target label
    ]);
    $messages[] = fitData(0, str_pad('Long Run', 10, "\0").pack('V', 3720000).str_pad('147bpm', 8, "\0"));

    // time_in_zone (MSG 216) for session (ref_mesg = 18)
    $messages[] = fitDefinition(1, 216, [
        [0, 1, 0x00],   // reference_mesg (18 = session)
        [15, 2, 0x84],  // threshold_power (394 W)
        [9, 12, 0x84],  // 6 x uint16 power zone bounds
    ]);
    $messages[] = fitData(1, chr(18).pack('v', 394).pack('v*', 255, 318, 353, 394, 455, 4000));

    // record (MSG 20) with extended running & physiology fields
    $messages[] = fitDefinition(2, 20, [
        [253, 4, 0x86], // timestamp
        [5, 4, 0x86],   // distance (/100)
        [73, 4, 0x86],  // enhanced_speed (/1000)
        [140, 4, 0x86], // grade_adjusted_speed (/1000)
        [78, 4, 0x86],  // enhanced_altitude
        [3, 1, 0x02],   // hr
        [4, 1, 0x02],   // single-foot cadence (78 -> 156 spm)
        [53, 1, 0x02],  // fractional_cadence (64/128 = 0.5 -> +1 spm)
        [7, 2, 0x84],   // power
        [13, 1, 0x01],  // temperature (sint8)
        [39, 2, 0x84],  // vertical_oscillation (/100 cm)
        [41, 2, 0x84],  // stance_time GCT (/10 ms)
        [83, 2, 0x84],  // vertical_ratio (/100 %)
        [85, 2, 0x84],  // step_length (/10000 m)
        [90, 1, 0x01],  // performance_condition (sint8: -1, +2)
        [108, 2, 0x84], // enhanced_respiration_rate (/100 brpm)
        [137, 1, 0x02], // stamina_potential
        [138, 1, 0x02], // stamina
    ]);

    // 2 records covering 1 km: t=0s (0m, perf=+2, stam=100/100) and t=360s (1000m, perf=-1, stam=80/78)
    $messages[] = fitData(2,
        pack('V', $baseFitTs)
        .pack('V', 0)
        .pack('V', 2778)
        .pack('V', 2857) // native GAP = 350.0 s/km
        .pack('V', 3000) // 100m
        .chr(150)
        .chr(78)
        .chr(64)         // (78 + 0.5) * 2 = 157 spm
        .pack('v', 340)
        .chr(16)         // 16 C
        .pack('v', 885)  // 8.85 cm
        .pack('v', 2960) // 296.0 ms
        .pack('v', 899)  // 8.99 %
        .pack('v', 9954) // 0.995 m
        .chr(2)          // +2 perf condition
        .pack('v', 3480) // 34.8 brpm
        .chr(100)        // potential 100
        .chr(100),       // stamina 100
    );

    $messages[] = fitData(2,
        pack('V', $baseFitTs + 360)
        .pack('V', 100000)
        .pack('V', 2778)
        .pack('V', 2857)
        .pack('V', 3000)
        .chr(154)
        .chr(78)
        .chr(0)          // 78 * 2 = 156 spm
        .pack('v', 345)
        .chr(14)         // 14 C -> avg 15.0 C
        .pack('v', 890)
        .pack('v', 2940)
        .pack('v', 900)
        .pack('v', 10000)
        .chr(0xFF & -1)  // -1 perf condition (sint8 = 255)
        .pack('v', 3520)
        .chr(80)
        .chr(78),
    );

    // lap (MSG 19) with single-foot cadence + native GAP speed
    $messages[] = fitDefinition(3, 19, [
        [7, 4, 0x86],
        [8, 4, 0x86],
        [9, 4, 0x86],
        [110, 4, 0x86],
        [161, 4, 0x86], // avg_grade_adjusted_speed
        [17, 1, 0x02],  // avg_cadence (78)
        [80, 1, 0x02],  // fractional_avg_cadence (64 -> +0.5 -> 157 spm)
    ]);
    $messages[] = fitData(3,
        pack('V', 360000)
        .pack('V', 360000)
        .pack('V', 100000)
        .pack('V', 2778)
        .pack('V', 2857)
        .chr(78)
        .chr(64),
    );

    // run/walk split (MSG 312)
    $messages[] = fitDefinition(4, 312, [
        [0, 1, 0x00],  // split_type (17=run, 18=walk)
        [2, 4, 0x86],  // timer ms
        [3, 4, 0x86],  // distance cm
        [9, 4, 0x86],  // start_time
        [27, 4, 0x86], // end_time
    ]);
    $messages[] = fitData(4, chr(17).pack('V', 300000).pack('V', 85000).pack('V', $baseFitTs).pack('V', $baseFitTs + 300));
    $messages[] = fitData(4, chr(18).pack('V', 60000).pack('V', 15000).pack('V', $baseFitTs + 300).pack('V', $baseFitTs + 360));

    // session (MSG 18) with sport=1 (running), stamina, feel/rpe, execution score, BB, sweat, BMR
    $messages[] = fitDefinition(5, 18, [
        [5, 1, 0x00],   // sport = 1 (running)
        [185, 1, 0x02], // execution_score = 88
        [192, 1, 0x02], // workout_feel = 50
        [193, 1, 0x02], // workout_rpe = 80
        [196, 2, 0x84], // bmr_calories = 99
        [178, 2, 0x84], // sweat_loss_ml = 1047
        [205, 1, 0x02], // begin_stamina = 100
        [206, 1, 0x02], // end_stamina = 43
        [207, 1, 0x02], // min_stamina = 41
        [211, 4, 0x86], // avg_grade_adjusted_speed = 2691
        [212, 1, 0x02], // wind_enabled = 1
        [215, 1, 0x02], // begin_body_battery = 78
        [216, 1, 0x02], // end_body_battery = 61
    ]);
    $messages[] = fitData(5,
        chr(1).chr(88).chr(50).chr(80).pack('v', 99).pack('v', 1047)
        .chr(100).chr(43).chr(41).pack('V', 2691).chr(1).chr(78).chr(61),
    );

    $parsed = (new FitParser)->parseBinary(buildFitBinary($messages));

    expect($parsed->records[0]['cadence'])->toBe(157)
        ->and($parsed->records[1]['cadence'])->toBe(156)
        ->and($parsed->records[0]['performance_condition'])->toBe(2)
        ->and($parsed->records[1]['performance_condition'])->toBe(-1)
        ->and($parsed->records[0]['stamina'])->toBe(100)
        ->and($parsed->records[1]['stamina'])->toBe(78)
        ->and($parsed->records[1]['stamina_potential'])->toBe(80)
        ->and($parsed->records[0]['gct'])->toBe(296.0)
        ->and($parsed->records[0]['vertical_ratio'])->toBe(8.99)
        ->and($parsed->records[0]['vertical_oscillation'])->toBe(8.85)
        ->and($parsed->records[0]['stride_length'])->toBe(0.995)
        ->and($parsed->records[0]['respiration'])->toBe(34.8)
        ->and($parsed->laps[0]['avg_cadence'])->toBe(157)
        ->and($parsed->laps[0]['gap_seconds_per_km'])->toBe(350.02)
        ->and($parsed->kmSplits[0]['gap_seconds_per_km'])->toBe(350.02)
        ->and($parsed->runWalkSegments)->toHaveCount(2)
        ->and($parsed->runWalkSegments[0]['type'])->toBe('run')
        ->and($parsed->runWalkSegments[1]['type'])->toBe('walk')
        ->and($parsed->sessionSummary)->toMatchArray([
            'begin_stamina' => 100,
            'end_stamina' => 43,
            'min_stamina' => 41,
            'workout_feel' => 50,
            'workout_rpe' => 80,
            'execution_score' => 88,
            'begin_body_battery' => 78,
            'end_body_battery' => 61,
            'bmr_calories' => 99,
            'sweat_loss_ml' => 1047,
            'avg_grade_adjusted_speed' => 2.691,
            'avg_temperature' => 15.0,
            'wind_enabled' => true,
            'threshold_power' => 394.0,
            'power_zone_bounds' => [255, 318, 353, 394, 455],
            'run_duration_seconds' => 300.0,
            'walk_duration_seconds' => 60.0,
        ])
        ->and($parsed->sessionSummary['workout_meta'])->toMatchArray([
            'name' => 'Long Run',
            'target_label' => '147bpm',
        ])
        ->and($parsed->sessionSummary)->not->toHaveKey('sport_id')
        ->and($parsed->sessionMetadata)->toBe(['sport_id' => 1]);
});

it('decodes cycling L/R balance, pedaling dynamics, MTB grit/flow streams, and jump events', function () {
    $baseFitTs = 1161204100;
    $messages = [];

    // record (MSG 20) with L/R balance, TE/PS, grit, flow
    $messages[] = fitDefinition(0, 20, [
        [253, 4, 0x86],
        [4, 1, 0x02],   // biking cadence (85 rpm - must NOT be doubled)
        [30, 1, 0x02],  // left_right_balance (0x80 | 52 = 180 -> 52% right -> 48% left)
        [43, 1, 0x02],  // left_te (150 / 2 = 75.0%)
        [44, 1, 0x02],  // right_te (156 / 2 = 78.0%)
        [45, 1, 0x02],  // left_ps (44 / 2 = 22.0%)
        [46, 1, 0x02],  // right_ps (48 / 2 = 24.0%)
        [114, 4, 0x88], // grit (float32)
        [115, 4, 0x88], // flow (float32)
    ]);
    $messages[] = fitData(0,
        pack('V', $baseFitTs)
        .chr(85)
        .chr(0x80 | 52)
        .chr(150).chr(156).chr(44).chr(48)
        .pack('g', 14.5)
        .pack('g', 3.25),
    );

    // jump (MSG 285)
    $messages[] = fitDefinition(1, 285, [
        [253, 4, 0x86],
        [0, 4, 0x88], // distance m
        [3, 4, 0x88], // hang time s
        [4, 4, 0x88], // score
        [5, 4, 0x85], // lat
        [6, 4, 0x85], // lng
        [7, 2, 0x84], // speed (/1000)
    ]);
    $messages[] = fitData(1,
        pack('V', $baseFitTs + 45)
        .pack('g', 3.14)
        .pack('g', 0.38)
        .pack('g', 77.4)
        .pack('V', degreesToSemicircles(-43.59))
        .pack('V', degreesToSemicircles(172.64))
        .pack('v', 8360),
    );

    // session (MSG 18): cycling (sport = 2)
    $messages[] = fitDefinition(2, 18, [
        [5, 1, 0x00],
    ]);
    $messages[] = fitData(2, chr(2));

    $parsed = (new FitParser)->parseBinary(buildFitBinary($messages));

    expect($parsed->records[0]['cadence'])->toBe(85)
        ->and($parsed->records[0]['left_balance'])->toBe(48.0)
        ->and($parsed->records[0]['left_te'])->toBe(75.0)
        ->and($parsed->records[0]['right_te'])->toBe(78.0)
        ->and($parsed->records[0]['left_ps'])->toBe(22.0)
        ->and($parsed->records[0]['right_ps'])->toBe(24.0)
        ->and($parsed->records[0]['grit'])->toBe(14.5)
        ->and($parsed->records[0]['flow'])->toBe(3.25)
        ->and($parsed->jumps)->toHaveCount(1)
        ->and($parsed->jumps[0])->toMatchArray([
            'time' => 45.0,
            'distance_meters' => 3.14,
            'hang_time_seconds' => 0.38,
            'score' => 77.4,
            'speed' => 8.36,
        ]);
});

it('populates all standalone sessionMetadata keys for running with doubled fractional cadence and enhanced speeds', function () {
    // 2026-04-12T06:30:00Z -> unix 1775975400 -> FIT ts = 1775975400 - 631065600 = 1144909800
    $fitStartTs = 1144909800;
    $messages = [];

    // file_id (MSG 0)
    $messages[] = fitDefinition(0, 0, [
        [0, 1, 0x00], // type
        [1, 2, 0x84], // manufacturer_id (1 = Garmin)
        [2, 2, 0x84], // product_id (4257 = Forerunner 965)
    ]);
    $messages[] = fitData(0, chr(4).pack('v', 1).pack('v', 4257));

    // session (MSG 18) with every standalone metadata field
    $messages[] = fitDefinition(1, 18, [
        [2, 4, 0x86],   // start_time
        [5, 1, 0x00],   // sport_id (1 = running)
        [6, 1, 0x00],   // sub_sport_id (3 = trail)
        [7, 4, 0x86],   // total_elapsed_time (/1000)
        [8, 4, 0x86],   // total_timer_time (/1000)
        [9, 4, 0x86],   // total_distance (/100)
        [11, 2, 0x84],  // total_calories
        [14, 2, 0x84],  // legacy avg_speed (/1000, overridden by 124)
        [15, 2, 0x84],  // legacy max_speed (/1000, overridden by 125)
        [16, 1, 0x02],  // avg_hr
        [17, 1, 0x02],  // max_hr
        [18, 1, 0x02],  // avg_cadence (88 + 32/128 = 88.25 -> doubled = 176.5)
        [19, 1, 0x02],  // max_cadence (94 + 64/128 = 94.5 -> doubled = 189.0)
        [20, 2, 0x84],  // avg_power
        [21, 2, 0x84],  // max_power
        [22, 2, 0x84],  // total_ascent
        [23, 2, 0x84],  // total_descent
        [92, 1, 0x02],  // avg_fractional_cadence (32/128 = 0.25)
        [93, 1, 0x02],  // max_fractional_cadence (64/128 = 0.5)
        [124, 4, 0x86], // enhanced_avg_speed (/1000)
        [125, 4, 0x86], // enhanced_max_speed (/1000)
    ]);
    $messages[] = fitData(1,
        pack('V', $fitStartTs)
        .chr(1)
        .chr(3)
        .pack('V', 3665500)  // 3665.5s
        .pack('V', 3600250)  // 3600.25s
        .pack('V', 1425050)  // 14250.5m
        .pack('v', 892)
        .pack('v', 3500)     // legacy 3.5 m/s (overridden)
        .pack('v', 4800)     // legacy 4.8 m/s (overridden)
        .chr(156)
        .chr(178)
        .chr(88)
        .chr(94)
        .pack('v', 342)
        .pack('v', 495)
        .pack('v', 285)
        .pack('v', 279)
        .chr(32)
        .chr(64)
        .pack('V', 3958)     // enhanced 3.958 m/s
        .pack('V', 5412),    // enhanced 5.412 m/s
    );

    $parsed = (new FitParser)->parseBinary(buildFitBinary($messages));

    expect($parsed->sessionSummary)->toBe([])
        ->and($parsed->sessionMetadata)->toBe([
            'sport_id' => 1,
            'sub_sport_id' => 3,
            'start_timestamp_utc' => '2026-04-12T06:30:00Z',
            'total_elapsed_time' => 3665.5,
            'total_timer_time' => 3600.25,
            'total_distance' => 14250.5,
            'total_calories' => 892,
            'avg_hr' => 156,
            'max_hr' => 178,
            'avg_speed' => 3.958,
            'max_speed' => 5.412,
            'total_ascent' => 285.0,
            'total_descent' => 279.0,
            'avg_power' => 342.0,
            'max_power' => 495.0,
            'avg_cadence' => 176.5,
            'max_cadence' => 189.0,
            'manufacturer_id' => 1,
            'product_id' => 4257,
        ]);
});

it('keeps cycling cadence undoubled and falls back to legacy session speeds when enhanced speeds are invalid', function () {
    $messages = [];

    $messages[] = fitDefinition(0, 18, [
        [5, 1, 0x00],   // sport_id = 2 (cycling)
        [14, 2, 0x84],  // legacy avg_speed = 9.25 m/s
        [15, 2, 0x84],  // legacy max_speed = 14.8 m/s
        [18, 1, 0x02],  // avg_cadence = 86 rpm
        [19, 1, 0x02],  // max_cadence = 112 rpm
        [92, 1, 0x02],  // avg_fractional_cadence = 64/128 (0.5)
        [93, 1, 0x02],  // max_fractional_cadence = 0xFF (invalid)
        [124, 4, 0x86], // enhanced_avg_speed = 0xFFFFFFFF (invalid -> fallback to field 14)
        [125, 4, 0x86], // enhanced_max_speed = 0xFFFFFFFF (invalid -> fallback to field 15)
    ]);
    $messages[] = fitData(0,
        chr(2)
        .pack('v', 9250)
        .pack('v', 14800)
        .chr(86)
        .chr(112)
        .chr(64)
        .chr(0xFF)
        .pack('V', 0xFFFFFFFF)
        .pack('V', 0xFFFFFFFF),
    );

    $parsed = (new FitParser)->parseBinary(buildFitBinary($messages));

    expect($parsed->sessionMetadata)->toBe([
        'sport_id' => 2,
        'avg_speed' => 9.25,
        'max_speed' => 14.8,
        'avg_cadence' => 86.5,
        'max_cadence' => 112.0,
    ]);
});

it('omits missing and invalid sentinel fields from sessionMetadata', function () {
    $messages = [];

    // file_id with invalid manufacturer & product sentinels
    $messages[] = fitDefinition(0, 0, [
        [1, 2, 0x84], // manufacturer_id = 0xFFFF (invalid)
        [2, 2, 0x84], // product_id = 0xFFFF (invalid)
    ]);
    $messages[] = fitData(0, pack('v', 0xFFFF).pack('v', 0xFFFF));

    // session with all invalid sentinels
    $messages[] = fitDefinition(1, 18, [
        [2, 4, 0x86],   // start_time = 0xFFFFFFFF
        [5, 1, 0x00],   // sport_id = 0xFF
        [6, 1, 0x00],   // sub_sport_id = 0xFF
        [7, 4, 0x86],   // total_elapsed_time = 0xFFFFFFFF
        [8, 4, 0x86],   // total_timer_time = 0xFFFFFFFF
        [9, 4, 0x86],   // total_distance = 0xFFFFFFFF
        [11, 2, 0x84],  // total_calories = 0xFFFF
        [14, 2, 0x84],  // avg_speed = 0xFFFF
        [15, 2, 0x84],  // max_speed = 0xFFFF
        [16, 1, 0x02],  // avg_hr = 0xFF
        [17, 1, 0x02],  // max_hr = 0xFF
        [18, 1, 0x02],  // avg_cadence = 0xFF
        [19, 1, 0x02],  // max_cadence = 0xFF
        [20, 2, 0x84],  // avg_power = 0xFFFF
        [21, 2, 0x84],  // max_power = 0xFFFF
        [22, 2, 0x84],  // total_ascent = 0xFFFF
        [23, 2, 0x84],  // total_descent = 0xFFFF
        [92, 1, 0x02],  // avg_fractional_cadence = 0xFF
        [93, 1, 0x02],  // max_fractional_cadence = 0xFF
        [124, 4, 0x86], // enhanced_avg_speed = 0xFFFFFFFF
        [125, 4, 0x86], // enhanced_max_speed = 0xFFFFFFFF
    ]);
    $messages[] = fitData(1,
        pack('V', 0xFFFFFFFF)
        .chr(0xFF)
        .chr(0xFF)
        .pack('V', 0xFFFFFFFF)
        .pack('V', 0xFFFFFFFF)
        .pack('V', 0xFFFFFFFF)
        .pack('v', 0xFFFF)
        .pack('v', 0xFFFF)
        .pack('v', 0xFFFF)
        .chr(0xFF)
        .chr(0xFF)
        .chr(0xFF)
        .chr(0xFF)
        .pack('v', 0xFFFF)
        .pack('v', 0xFFFF)
        .pack('v', 0xFFFF)
        .pack('v', 0xFFFF)
        .chr(0xFF)
        .chr(0xFF)
        .pack('V', 0xFFFFFFFF)
        .pack('V', 0xFFFFFFFF),
    );

    $parsed = (new FitParser)->parseBinary(buildFitBinary($messages));

    expect($parsed->sessionMetadata)->toBe([]);
});
