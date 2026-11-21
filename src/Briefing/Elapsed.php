<?php

declare(strict_types=1);

namespace App\Briefing;

final class Elapsed
{
    public const int MILLISECONDS_PER_SECOND = 1000;
    private const string SECONDS_WITH_MICROSECONDS = 'U.u';

    public static function millisecondsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) round(self::secondsBetween($from, $to) * self::MILLISECONDS_PER_SECOND);
    }

    public static function secondsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        return self::seconds($to) - self::seconds($from);
    }

    private static function seconds(\DateTimeImmutable $moment): float
    {
        return (float) $moment->format(self::SECONDS_WITH_MICROSECONDS);
    }
}
