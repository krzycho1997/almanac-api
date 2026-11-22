<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Request;

use App\Briefing\Exception\InvalidLocationException;
use App\Briefing\Validator\Latitude;
use App\Briefing\Validator\Longitude;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BriefingQuery
{
    public function __construct(
        #[Latitude]
        #[Assert\When(static function (self $query): bool {
            return null !== $query->lon;
        }, constraints: new Assert\NotNull(message: InvalidLocationException::ONLY_ONE_COORDINATE))]
        public mixed $lat = null,
        #[Longitude]
        #[Assert\When(static function (self $query): bool {
            return null !== $query->lat;
        }, constraints: new Assert\NotNull(message: InvalidLocationException::ONLY_ONE_COORDINATE))]
        public mixed $lon = null,
    ) {
    }
}
