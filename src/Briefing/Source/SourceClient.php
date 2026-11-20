<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use App\Briefing\Location;

interface SourceClient
{
    public function covers(\DateTimeImmutable $date): bool;

    #[\NoDiscard]
    public function request(\DateTimeImmutable $date, Location $location): PendingFetch;
}
