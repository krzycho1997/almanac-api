<?php

declare(strict_types=1);

namespace App\Briefing\Exception;

final class InvalidLocationException extends \InvalidArgumentException
{
    public static function latitudeOutsideTheGlobe(): self
    {
        return new self('Latitude must be between -90 and 90.');
    }

    public static function longitudeOutsideTheGlobe(): self
    {
        return new self('Longitude must be between -180 and 180.');
    }

    public static function withOnlyOneCoordinate(): self
    {
        return new self('Latitude and longitude must be given together.');
    }

    public static function defaultWithoutName(): self
    {
        return new self('The default location needs a name.');
    }
}
