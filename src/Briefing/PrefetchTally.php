<?php

declare(strict_types=1);

namespace App\Briefing;

final class PrefetchTally
{
    public private(set) int $dates = 0;
    public private(set) int $failures = 0;
    public private(set) bool $lastDateFetched = false;

    public function __construct(public readonly \DateTimeImmutable $startedAt)
    {
    }

    public function add(BriefingResult $briefing): void
    {
        ++$this->dates;
        $this->failures += \count($briefing->failures());
        $this->lastDateFetched = $briefing->hasFetched();
    }
}
