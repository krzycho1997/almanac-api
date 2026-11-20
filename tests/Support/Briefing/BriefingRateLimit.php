<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Controller\BriefingController;

final class BriefingRateLimit
{
    public const int LIMIT = 60;
    public const int WINDOW_SECONDS = 60;
    public const string LIMITER = 'limiter.'.BriefingController::RATE_LIMITER;

    private function __construct()
    {
    }
}
