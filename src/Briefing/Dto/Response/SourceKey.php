<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

enum SourceKey: string
{
    case Apod = 'apod';
    case OnThisDay = 'onThisDay';
    case Weather = 'weather';
}
