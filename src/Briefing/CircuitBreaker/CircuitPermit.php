<?php

declare(strict_types=1);

namespace App\Briefing\CircuitBreaker;

enum CircuitPermit
{
    case Call;
    case Trial;
    case Refused;

    public function allowsCall(): bool
    {
        return match ($this) {
            self::Call, self::Trial => true,
            self::Refused => false,
        };
    }
}
