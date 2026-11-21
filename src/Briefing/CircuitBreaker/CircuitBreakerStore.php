<?php

declare(strict_types=1);

namespace App\Briefing\CircuitBreaker;

interface CircuitBreakerStore
{
    public function openUntil(string $circuit): ?\DateTimeImmutable;

    public function recordFailure(string $circuit, \DateTimeImmutable $forgetAt): int;

    public function forgetFailures(string $circuit): void;

    public function open(string $circuit, \DateTimeImmutable $until): void;

    public function claimTrial(string $circuit, \DateTimeImmutable $leaseUntil): bool;

    public function close(string $circuit): void;
}
