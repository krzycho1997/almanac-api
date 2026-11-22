<?php

declare(strict_types=1);

namespace App\Briefing\Mapper;

use App\Briefing\Dto\Request\BriefingQuery;
use App\Briefing\Location;

final readonly class BriefingQueryMapper
{
    public function convertToLocation(BriefingQuery $query, Location $default): Location
    {
        return Location::fromOptional(self::coordinate($query->lat), self::coordinate($query->lon), $default);
    }

    private static function coordinate(mixed $value): ?float
    {
        if (\is_string($value)) {
            return (float) $value;
        }

        return null;
    }
}
