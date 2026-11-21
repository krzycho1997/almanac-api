<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

enum CircuitStateValue: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
