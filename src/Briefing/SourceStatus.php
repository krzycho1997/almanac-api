<?php

declare(strict_types=1);

namespace App\Briefing;

enum SourceStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
}
