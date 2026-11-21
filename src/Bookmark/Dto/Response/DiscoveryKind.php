<?php

declare(strict_types=1);

namespace App\Bookmark\Dto\Response;

enum DiscoveryKind: string
{
    case Apod = 'apod';
    case Event = 'event';
    case Person = 'person';
}
