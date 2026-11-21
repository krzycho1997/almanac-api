<?php

declare(strict_types=1);

namespace App\Briefing;

final class BriefingDay
{
    public const string FORMAT = 'Y-m-d';
    public const string FIRST = '1995-06-16';
    private const string MIDNIGHT_FORMAT = '!'.self::FORMAT;

    public static function startOf(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat(self::MIDNIGHT_FORMAT, $value, $timezone);

        if ($day instanceof \DateTimeImmutable && $value === $day->format(self::FORMAT)) {
            return $day;
        }

        return null;
    }
}
