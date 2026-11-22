<?php

declare(strict_types=1);

namespace App\Briefing\Mapper;

use App\Briefing\Location;

final readonly class CoordinateMapper
{
    public function convertToLocation(mixed $latitude, mixed $longitude, Location $default): Location
    {
        return Location::fromOptional(self::coordinate($latitude), self::coordinate($longitude), $default);
    }

    private static function coordinate(mixed $value): ?float
    {
        if (\is_string($value)) {
            return (float) $value;
        }

        return null;
    }
}
