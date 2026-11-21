<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\CircuitBreaker;

use App\Briefing\CircuitBreaker\CircuitBreakerStore;
use Psr\Clock\ClockInterface;

final class InMemoryCircuitBreakerStore implements CircuitBreakerStore
{
    private const int FIRST_FAILURE = 1;
    private const int NO_FAILURES = 0;

    /**
     * @var array<string, array{int, \DateTimeImmutable}>
     */
    private array $failures = [];

    /**
     * @var array<string, \DateTimeImmutable>
     */
    private array $openUntil = [];

    /**
     * @var array<string, \DateTimeImmutable>
     */
    private array $trials = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function openUntil(string $circuit): ?\DateTimeImmutable
    {
        return $this->openUntil[$circuit] ?? null;
    }

    public function recordFailure(string $circuit, \DateTimeImmutable $forgetAt): int
    {
        if ($this->isPast($forgetAt)) {
            unset($this->failures[$circuit]);

            return self::FIRST_FAILURE;
        }

        $count = $this->liveFailures($circuit) + 1;
        $this->failures[$circuit] = [$count, $forgetAt];

        return $count;
    }

    public function forgetFailures(string $circuit): void
    {
        unset($this->failures[$circuit]);
    }

    public function open(string $circuit, \DateTimeImmutable $until): void
    {
        $this->openUntil[$circuit] = $until;
        unset($this->failures[$circuit], $this->trials[$circuit]);
    }

    public function claimTrial(string $circuit, \DateTimeImmutable $leaseUntil): bool
    {
        if ($this->isLeased($circuit)) {
            return false;
        }

        $this->trials[$circuit] = $leaseUntil;

        return true;
    }

    public function close(string $circuit): void
    {
        unset($this->failures[$circuit], $this->openUntil[$circuit], $this->trials[$circuit]);
    }

    private function liveFailures(string $circuit): int
    {
        [$count, $forgetAt] = $this->failures[$circuit] ?? [self::NO_FAILURES, $this->clock->now()];

        if ($this->isPast($forgetAt)) {
            return self::NO_FAILURES;
        }

        return $count;
    }

    private function isLeased(string $circuit): bool
    {
        return ($this->trials[$circuit] ?? $this->clock->now()) > $this->clock->now();
    }

    private function isPast(\DateTimeImmutable $moment): bool
    {
        return $moment <= $this->clock->now();
    }
}
