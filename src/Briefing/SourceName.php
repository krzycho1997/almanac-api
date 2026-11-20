<?php

declare(strict_types=1);

namespace App\Briefing;

enum SourceName: string
{
    case Apod = 'apod';
    case OnThisDay = 'on_this_day';
    case Weather = 'weather';
}
