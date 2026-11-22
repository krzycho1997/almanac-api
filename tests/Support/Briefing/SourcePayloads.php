<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Model\SourcePayload;
use App\Briefing\SourceName;

final class SourcePayloads
{
    public static function of(SourceName $source): SourcePayload
    {
        return match ($source) {
            SourceName::Apod => ApodPayloads::picture(),
            SourceName::OnThisDay => OnThisDayPayloads::feed(),
            SourceName::Weather => OpenMeteoPayloads::dailyWeather(),
        };
    }
}
