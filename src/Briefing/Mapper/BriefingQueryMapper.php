<?php

declare(strict_types=1);

namespace App\Briefing\Mapper;

use App\Briefing\Dto\Request\BriefingQuery;
use App\Briefing\Location;

final readonly class BriefingQueryMapper
{
    public function __construct(
        private CoordinateMapper $coordinates,
    ) {
    }

    public function convertToLocation(BriefingQuery $query, Location $default): Location
    {
        return $this->coordinates->convertToLocation($query->lat, $query->lon, $default);
    }
}
