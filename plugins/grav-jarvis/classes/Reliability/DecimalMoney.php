<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Reliability;

use InvalidArgumentException;

final class DecimalMoney
{
    public const SCALE = 1000000000;

    public static function parseNanos(string $amount): int
    {
        if (preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,9}))?$/D', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Money values must be bounded decimal strings with at most nine places.');
        }
        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 9, '0');
        return ($whole * self::SCALE) + (int) $fraction;
    }

    public static function formatNanos(int $nanos): string
    {
        if ($nanos < 0) {
            throw new InvalidArgumentException('Money values cannot be negative.');
        }
        $whole = intdiv($nanos, self::SCALE);
        $fraction = rtrim(str_pad((string) ($nanos % self::SCALE), 9, '0', STR_PAD_LEFT), '0');
        return $fraction === '' ? (string) $whole : $whole . '.' . $fraction;
    }

    public static function priceUnits(int $rateNanosPerMillion, int $units): int
    {
        if ($rateNanosPerMillion < 0 || $units < 0 || $units > 1000000000) {
            throw new InvalidArgumentException('Pricing inputs are outside Jarvis safety bounds.');
        }
        $million = 1000000;
        $whole = intdiv($rateNanosPerMillion, $million) * $units;
        $remainder = ($rateNanosPerMillion % $million) * $units;
        return $whole + intdiv($remainder + intdiv($million, 2), $million);
    }
}
