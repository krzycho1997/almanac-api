<?php

declare(strict_types=1);

namespace App\Briefing\CircuitBreaker;

use App\Briefing\FailureKind;
use App\Briefing\SourceFailure;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use Psr\Clock\ClockInterface;

final readonly class CircuitBreaker
{
    private const int OUTAGES_TO_OPEN = 3;
    private const string OPEN_AFTER_OUTAGES = 'PT60S';
    private const string OPEN_AFTER_RATE_LIMIT = 'PT15M';
    private const string SHORTEST_RATE_LIMIT_OPENING = 'PT60S';
    private const string TRIAL_LEASE = 'PT30S';
    private const string FAILURE_WINDOW = 'PT30M';

    public function __construct(
        private CircuitBreakerStore $store,
        private ClockInterface $clock,
    ) {
    }

    #[\NoDiscard]
    public function tryAcquire(SourceName $source): CircuitPermit
    {
        return match ($this->state($source)) {
            CircuitState::Closed => CircuitPermit::Call,
            CircuitState::Open => CircuitPermit::Refused,
            CircuitState::HalfOpen => $this->trialFor($source),
        };
    }

    public function state(SourceName $source): CircuitState
    {
        $openUntil = $this->store->openUntil($source->value);

        if (null === $openUntil) {
            return CircuitState::Closed;
        }

        if ($this->clock->now() < $openUntil) {
            return CircuitState::Open;
        }

        return CircuitState::HalfOpen;
    }

    public function record(SourceResult $result, CircuitPermit $permit): void
    {
        if (CircuitPermit::Call === $permit) {
            $this->afterCall($result->source->value, self::outageOf($result));

            return;
        }

        if (CircuitPermit::Trial === $permit) {
            $this->afterTrial($result->source->value, self::outageOf($result));
        }
    }

    private function trialFor(SourceName $source): CircuitPermit
    {
        if ($this->store->claimTrial($source->value, $this->after(self::TRIAL_LEASE))) {
            return CircuitPermit::Trial;
        }

        return CircuitPermit::Refused;
    }

    private function afterCall(string $circuit, ?SourceFailure $outage): void
    {
        if (null === $outage) {
            $this->store->forgetFailures($circuit);

            return;
        }

        if ($this->opensAfter($circuit, $outage)) {
            $this->openAtLeastUntil($circuit, $this->openUntil($outage));
        }
    }

    private function opensAfter(string $circuit, SourceFailure $outage): bool
    {
        if (FailureKind::RateLimited === $outage->kind) {
            return true;
        }

        return self::OUTAGES_TO_OPEN <= $this->store->recordFailure($circuit, $this->after(self::FAILURE_WINDOW));
    }

    private function openAtLeastUntil(string $circuit, \DateTimeImmutable $until): void
    {
        if ($this->openingEndsBefore($circuit, $until)) {
            $this->store->open($circuit, $until);
        }
    }

    private function openingEndsBefore(string $circuit, \DateTimeImmutable $until): bool
    {
        $current = $this->store->openUntil($circuit);

        if (null === $current) {
            return true;
        }

        return $current < $until;
    }

    private function afterTrial(string $circuit, ?SourceFailure $outage): void
    {
        if ($outage instanceof SourceFailure) {
            $this->store->open($circuit, $this->openUntil($outage));

            return;
        }

        $this->store->close($circuit);
    }

    private static function outageOf(SourceResult $result): ?SourceFailure
    {
        if (true === $result->failure?->kind->countsAsOutage()) {
            return $result->failure;
        }

        return null;
    }

    private function openUntil(SourceFailure $outage): \DateTimeImmutable
    {
        if (FailureKind::RateLimited === $outage->kind) {
            return $this->untilRetry($outage->retryAt);
        }

        return $this->after(self::OPEN_AFTER_OUTAGES);
    }

    private function untilRetry(?\DateTimeImmutable $retryAt): \DateTimeImmutable
    {
        if (null === $retryAt) {
            return $this->after(self::OPEN_AFTER_RATE_LIMIT);
        }

        return max($retryAt, $this->after(self::SHORTEST_RATE_LIMIT_OPENING));
    }

    private function after(string $interval): \DateTimeImmutable
    {
        return $this->clock->now()->add(new \DateInterval($interval));
    }
}
