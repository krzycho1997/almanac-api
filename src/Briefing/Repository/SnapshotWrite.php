<?php

declare(strict_types=1);

namespace App\Briefing\Repository;

enum SnapshotWrite
{
    case Saved;
    case KeptNewer;

    public static function fromAffectedRows(int|string $rows): self
    {
        if (0 === (int) $rows) {
            return self::KeptNewer;
        }

        return self::Saved;
    }
}
