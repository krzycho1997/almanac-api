<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use App\Briefing\Location;
use App\Briefing\SnapshotScope;
use App\Briefing\SourceName;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface Source
{
    public function name(): SourceName;

    public function refreshAfter(): ?\DateInterval;

    public function scopeFor(\DateTimeImmutable $date, Location $location): SnapshotScope;

    public function client(): SourceClient;
}
