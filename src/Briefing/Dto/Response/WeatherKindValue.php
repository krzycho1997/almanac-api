<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

enum WeatherKindValue: string
{
    case Final = 'final';
    case Provisional = 'provisional';
    case Forecast = 'forecast';
}
