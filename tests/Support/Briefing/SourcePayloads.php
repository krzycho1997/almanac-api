<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\MediaType;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
use App\Briefing\Model\SourcePayload;
use App\Briefing\Model\WeatherKind;
use App\Briefing\SourceName;

final class SourcePayloads
{
    public const string CAESAR = 'https://en.wikipedia.org/wiki/Julius_Caesar';
    public const string HAUSER = 'https://en.wikipedia.org/wiki/Wings_Hauser';
    public const string THUMBNAIL = 'https://upload.wikimedia.org/hauser.jpg';
    private const int OVERCAST = 3;

    public static function apodPicture(MediaType $mediaType = MediaType::Image): ApodPicture
    {
        return new ApodPicture(new \DateTimeImmutable(BriefingResultBuilder::DATE), 'Pillars', 'Gas and dust.', $mediaType, 'https://apod.nasa.gov/a.jpg', null, null, new Attribution('Astronomy Picture of the Day', 'https://apod.nasa.gov/', null, null, true, null));
    }

    public static function onThisDayFeed(): OnThisDayFeed
    {
        return new OnThisDayFeed(
            [new HistoricalEvent(-44, 'Julius Caesar is assassinated.', [new PageRef('Julius Caesar', self::CAESAR, self::THUMBNAIL)])],
            [new NotablePerson(1947, 'Wings Hauser', 'American actor', self::HAUSER, self::THUMBNAIL)],
            [new NotablePerson(-44, 'Julius Caesar', null, self::CAESAR, null)],
            new Attribution('Wikipedia', 'https://en.wikipedia.org/', 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/', true, null),
        );
    }

    public static function dailyWeather(WeatherKind $kind = WeatherKind::Final, ?int $weatherCode = self::OVERCAST): DailyWeather
    {
        return new DailyWeather($kind, 'Europe/Warsaw', 1.2, 6.8, 0.4, 18.0, $weatherCode, '2026-11-05T06:44:00+01:00', '2026-11-05T16:02:00+01:00', 33480, new Attribution('Open-Meteo', 'https://open-meteo.com/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', true, 'Generated using Copernicus Climate Change Service information'));
    }

    public static function of(SourceName $source): SourcePayload
    {
        return match ($source) {
            SourceName::Apod => self::apodPicture(),
            SourceName::OnThisDay => self::onThisDayFeed(),
            SourceName::Weather => self::dailyWeather(),
        };
    }
}
