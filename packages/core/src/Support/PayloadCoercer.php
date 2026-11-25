<?php

declare(strict_types=1);

namespace Splitline\Core\Support;

use Carbon\CarbonImmutable;
use Throwable;

final class PayloadCoercer
{
    public static function asInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) round((float) $value);
        }

        return null;
    }

    public static function asNonNegativeInt(mixed $value): ?int
    {
        $int = self::asInt($value);

        return $int !== null && $int >= 0 ? $int : null;
    }

    public static function asFloat(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    public static function asString(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public static function asArray(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function asAssocArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result !== [] ? $result : null;
    }

    public static function extractDateString(mixed $value): ?string
    {
        $string = self::asString($value);

        if ($string === null) {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $string, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Convert a local-offset-shifted epoch millisecond timestamp without applying a timezone shift.
     */
    public static function timestampMsToLocal(mixed $value): ?CarbonImmutable
    {
        $ms = self::asInt($value);

        if ($ms === null || $ms <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMsUTC($ms);
    }

    public static function timestampMsToUtc(mixed $value): ?CarbonImmutable
    {
        $ms = self::asInt($value);

        if ($ms === null || $ms <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMsUTC($ms);
    }

    public static function parseDatetime(mixed $value, string $timezone): ?CarbonImmutable
    {
        $string = self::asString($value);

        if ($string === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($string, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    public static function msToSeconds(mixed $value): ?int
    {
        $ms = self::asNonNegativeInt($value);

        return $ms !== null ? (int) round($ms / 1000) : null;
    }

    public static function positiveMsToSeconds(mixed $value): ?int
    {
        $seconds = self::msToSeconds($value);

        return ($seconds !== null && $seconds > 0) ? $seconds : null;
    }
}
