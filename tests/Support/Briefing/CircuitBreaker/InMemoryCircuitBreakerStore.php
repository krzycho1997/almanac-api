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

    public function open(string $circuit, \DateTimeImmutable $until): bool
    {
        $previous = $this->openUntil[$circuit] ?? null;

        if (self::lastsAtLeastUntil($previous, $until)) {
            return false;
        }

        $this->openUntil[$circuit] = $until;
        unset($this->failures[$circuit], $this->trials[$circuit]);

        return null === $previous || $this->isPast($previous);
    }

    public function claimTrial(string $circuit, \DateTimeImmutable $leaseUntil): bool
    {
        if ($this->isHalfOpen($circuit)) {
            return $this->lease($circuit, $leaseUntil);
        }

        return false;
    }

    private function lease(string $circuit, \DateTimeImmutable $leaseUntil): bool
    {
        if ($this->isLeased($circuit)) {
            return false;
        }

        $this->trials[$circuit] = $leaseUntil;

        return true;
    }

    public function close(string $circuit): bool
    {
        $wasOpen = \array_key_exists($circuit, $this->openUntil);
        unset($this->failures[$circuit], $this->openUntil[$circuit], $this->trials[$circuit]);

        return $wasOpen;
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

    private static function lastsAtLeastUntil(?\DateTimeImmutable $previous, \DateTimeImmutable $until): bool
    {
        if (null === $previous) {
            return false;
        }

        return $previous >= $until;
    }

    private function isHalfOpen(string $circuit): bool
    {
        $openUntil = $this->openUntil[$circuit] ?? null;

        if (null === $openUntil) {
            return false;
        }

        return $this->isPast($openUntil);
    }
}
