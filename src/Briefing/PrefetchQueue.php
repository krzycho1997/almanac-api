<?php

declare(strict_types=1);

namespace App\Briefing;

interface PrefetchQueue
{
    public function enqueue(\DateTimeImmutable $date, Location $location, int $delayMs): void;
}
