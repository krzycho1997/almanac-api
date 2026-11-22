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
            SourceName::OnThisDay => SnapshotScope::undated($this->name(), $date->format('m-d')),
            SourceName::Apod, SourceName::Weather => SnapshotScope::dated($this->name(), $date->format(BriefingDay::FORMAT), $date),
        };
    }
}
