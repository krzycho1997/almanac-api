<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\CircuitBreaker;

use App\Briefing\CircuitBreaker\CircuitBreakerStore;
use App\Briefing\Elapsed;
use App\Tests\Support\Briefing\CircuitBreaker\CircuitBreakerStoreContract;
use App\Tests\Support\Briefing\CircuitBreaker\InMemoryCircuitBreakerStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class InMemoryCircuitBreakerStoreTest extends CircuitBreakerStoreContract
{
    private const string NOW = '2026-11-21 12:00:00.250 Europe/Warsaw';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
    }

    protected function store(): CircuitBreakerStore
    {
        return new InMemoryCircuitBreakerStore($this->clock);
    }

    protected function clock(): ClockInterface
    {
        return $this->clock;
    }

    protected function elapse(int $milliseconds): void
    {
        $this->clock->sleep($milliseconds / Elapsed::MILLISECONDS_PER_SECOND);
    }
}
