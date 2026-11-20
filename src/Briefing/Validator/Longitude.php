<?php

declare(strict_types=1);

namespace App\Briefing\Validator;

use App\Briefing\Location;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final class Longitude extends Coordinate
{
    protected function limit(): float
    {
        return Location::MAX_LONGITUDE;
    }
}
