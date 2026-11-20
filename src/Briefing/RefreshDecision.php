<?php

declare(strict_types=1);

namespace App\Briefing;

enum RefreshDecision
{
    case Fetch;
    case Wait;
    case Fresh;
    case Final;

    public function fetches(): bool
    {
        return self::Fetch === $this;
    }
}
