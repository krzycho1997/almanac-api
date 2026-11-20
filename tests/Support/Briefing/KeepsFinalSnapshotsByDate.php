<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\BriefingDay;
use App\Briefing\Location;
use App\Briefing\SnapshotScope;
use App\Briefing\SourceName;

trait KeepsFinalSnapshotsByDate
{
    public function refreshAfter(): ?\DateInterval
    {
        return null;
    }

    public function scopeFor(\DateTimeImmutable $date, Location $location): SnapshotScope
    {
        return match ($this->name()) {
            SourceName::OnThisDay => new SnapshotScope($this->name(), $date->format('m-d'), null),
            SourceName::Apod, SourceName::Weather => new SnapshotScope($this->name(), $date->format(BriefingDay::FORMAT), $date),
        };
    }
}
