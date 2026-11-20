<?php

declare(strict_types=1);

namespace App\Briefing\Model;

enum WeatherKind: string
{
    case Final = 'final';
    case Provisional = 'provisional';
    case Forecast = 'forecast';
}
