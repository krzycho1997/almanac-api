<?php

declare(strict_types=1);

namespace App\Briefing\Contract;

enum DiscoveryType: string
{
    case Apod = 'apod';
    case Event = 'event';
    case Person = 'person';
}
