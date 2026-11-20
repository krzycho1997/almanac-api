<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Request;

use App\Briefing\Location;
use App\Briefing\Validator\Latitude;
use App\Briefing\Validator\Longitude;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BriefingQuery
{
    private const string TOGETHER_MESSAGE = 'Latitude and longitude must be given together.';

    public function __construct(
        #[Latitude]
        #[Assert\When(static function (self $query): bool {
            return null !== $query->lon;
        }, constraints: new Assert\NotNull(message: self::TOGETHER_MESSAGE))]
        public mixed $lat = null,
        #[Longitude]
        #[Assert\When(static function (self $query): bool {
            return null !== $query->lat;
        }, constraints: new Assert\NotNull(message: self::TOGETHER_MESSAGE))]
        public mixed $lon = null,
    ) {
    }

    public function locationOr(Location $default): Location
    {
        return Location::fromOptional(self::coordinate($this->lat), self::coordinate($this->lon), $default);
    }

    private static function coordinate(mixed $value): ?float
    {
        if (\is_string($value)) {
            return (float) $value;
        }

        return null;
    }
}
