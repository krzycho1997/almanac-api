<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\CircuitBreaker;

use App\Briefing\CircuitBreaker\CircuitBreakerStore;

final class BrokenCircuitBreakerStore implements CircuitBreakerStore
{
    private const string BROKEN = 'A bug in the store.';

    public function openUntil(string $circuit): ?\DateTimeImmutable
    {
        throw new \LogicException(self::BROKEN);
    }

    public function recordFailure(string $circuit, \DateTimeImmutable $forgetAt): int
    {
        throw new \LogicException(self::BROKEN);
    }

    public function forgetFailures(string $circuit): void
    {
        throw new \LogicException(self::BROKEN);
    }

    public function open(string $circuit, \DateTimeImmutable $until): bool
    {
        throw new \LogicException(self::BROKEN);
    }

    public function claimTrial(string $circuit, \DateTimeImmutable $leaseUntil): bool
    {
        throw new \LogicException(self::BROKEN);
    }

    public function close(string $circuit): bool
    {
        throw new \LogicException(self::BROKEN);
    }
}
