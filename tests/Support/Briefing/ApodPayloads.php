<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\MediaType;

final class ApodPayloads
{
    public static function picture(MediaType $mediaType = MediaType::Image): ApodPicture
    {
        return new ApodPicture(new \DateTimeImmutable(BriefingResultBuilder::DATE), 'Pillars', 'Gas and dust.', $mediaType, 'https://apod.nasa.gov/a.jpg', null, null, new Attribution('Astronomy Picture of the Day', 'https://apod.nasa.gov/', null, null, true, null));
    }
}
