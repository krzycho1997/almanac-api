<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\BriefingDay;
use App\Briefing\Location;
use App\Briefing\SnapshotScope;

trait KeepsFinalSnapshotsByDate
{
    public function refreshAfter(): ?\DateInterval
    {
        return null;
    }

    public function scopeFor(\DateTimeImmutable $date, Location $location): SnapshotScope
    {
        return new SnapshotScope($this->name(), $date->format(BriefingDay::FORMAT), $date);
    }
}
