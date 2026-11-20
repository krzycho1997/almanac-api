<?php

declare(strict_types=1);

namespace App\Briefing;

enum FailureOutcome: string
{
    case Stale = 'stale';
    case Missing = 'missing';

    public static function of(SourceEntry $entry): self
    {
        if ($entry->hasData()) {
            return self::Stale;
        }

        return self::Missing;
    }
}
