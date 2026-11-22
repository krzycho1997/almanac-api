<?php

declare(strict_types=1);

namespace App\Briefing\Mapper;

use App\Briefing\BriefingDay;
use App\Briefing\DateRange;
use App\Briefing\Dto\Input\PrefetchInput;
use App\Briefing\Location;

final readonly class PrefetchInputMapper
{
    public function convertToRange(PrefetchInput $input, \DateTimeZone $timezone): DateRange
    {
        return DateRange::between(self::dayIn($input->from, $timezone), self::dayIn($input->to, $timezone));
    }

    public function convertToLocation(PrefetchInput $input, Location $default): Location
    {
        return Location::fromOptional(self::coordinate($input->lat), self::coordinate($input->lon), $default);
    }

    public function convertToDelayMs(PrefetchInput $input): int
    {
        return (int) $input->delay;
    }

    private static function dayIn(string $value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return BriefingDay::startOf($value, $timezone)
            ?? throw new \LogicException('The dates are read only after the input passed validation.');
    }

    private static function coordinate(?string $value): ?float
    {
        if (null === $value) {
            return null;
        }

        return (float) $value;
    }
}
