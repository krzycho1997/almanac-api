<?php

declare(strict_types=1);

namespace App\Briefing;

enum ProblemTiming
{
    case FailedNow;
    case InPause;
}
