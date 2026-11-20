<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final readonly class TickingClock implements ClockInterface
{
    private MockClock $clock;

    public function __construct(
        private float $tickSeconds,
    ) {
        $this->clock = new MockClock();
    }

    public function now(): \DateTimeImmutable
    {
        $this->clock->sleep($this->tickSeconds);

        return $this->clock->now();
    }
}
