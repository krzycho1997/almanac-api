<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\WeatherKind;

final class OpenMeteoPayloads
{
    private const int OVERCAST = 3;

    public static function dailyWeather(WeatherKind $kind = WeatherKind::Final, ?int $weatherCode = self::OVERCAST): DailyWeather
    {
        return new DailyWeather($kind, 'Europe/Warsaw', 1.2, 6.8, 0.4, 18.0, $weatherCode, '2026-11-05T06:44:00+01:00', '2026-11-05T16:02:00+01:00', 33480, new Attribution('Open-Meteo', 'https://open-meteo.com/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', true, 'Generated using Copernicus Climate Change Service information'));
    }
}
