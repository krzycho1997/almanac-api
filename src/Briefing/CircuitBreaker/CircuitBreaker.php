<?php

declare(strict_types=1);

namespace App\Briefing\CircuitBreaker;

use App\Briefing\Elapsed;
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
    private const ?int NOT_COUNTED = null;
    private const bool AFTER_CALL = false;
    private const bool AFTER_TRIAL = true;

    public function __construct(
        private CircuitBreakerStore $store,
        private ClockInterface $clock,
        private CircuitLog $log,
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

    public function refusedUntil(SourceName $source): \DateTimeImmutable
    {
        $trialFreeAgain = $this->after(self::TRIAL_LEASE);
        $openUntil = $this->store->openUntil($source->value);

        if (null === $openUntil) {
            return $trialFreeAgain;
        }

        return max($openUntil, $trialFreeAgain);
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
            $this->afterCall($result);

            return;
        }

        if (CircuitPermit::Trial === $permit) {
            $this->afterTrial($result->source, self::outageOf($result));
        }
    }

    private function trialFor(SourceName $source): CircuitPermit
    {
        if ($this->store->claimTrial($source->value, $this->after(self::TRIAL_LEASE))) {
            $this->log->halfOpened($source);

            return CircuitPermit::Trial;
        }

        return CircuitPermit::Refused;
    }

    private function afterCall(SourceResult $result): void
    {
        $outage = self::outageOf($result);

        if ($outage instanceof SourceFailure) {
            $this->afterOutage($result->source, $outage);

            return;
        }

        if ($result->hasFailed()) {
            return;
        }

        $this->store->forgetFailures($result->source->value);
    }

    private function afterOutage(SourceName $source, SourceFailure $outage): void
    {
        if (FailureKind::RateLimited === $outage->kind) {
            $this->openAtLeast($source, $outage, self::NOT_COUNTED);

            return;
        }

        $this->countOutage($source, $outage);
    }

    private function countOutage(SourceName $source, SourceFailure $outage): void
    {
        $failures = $this->store->recordFailure($source->value, $this->after(self::FAILURE_WINDOW));

        if (self::OUTAGES_TO_OPEN <= $failures) {
            $this->openAtLeast($source, $outage, $failures);
        }
    }

    private function openAtLeast(SourceName $source, SourceFailure $outage, ?int $failures): void
    {
        $this->open($source, $outage, $this->openUntil($outage), $failures, self::AFTER_CALL);
    }

    private function afterTrial(SourceName $source, ?SourceFailure $outage): void
    {
        if ($outage instanceof SourceFailure) {
            $this->open($source, $outage, $this->openUntil($outage), self::NOT_COUNTED, self::AFTER_TRIAL);

            return;
        }

        if ($this->store->close($source->value)) {
            $this->log->closed($source);
        }
    }

    private function open(SourceName $source, SourceFailure $outage, \DateTimeImmutable $until, ?int $failures, bool $trial): void
    {
        if ($this->store->open($source->value, $until)) {
            $this->log->opened($source, $outage->kind, $failures, $this->secondsUntil($until), $trial);
        }
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

    private function secondsUntil(\DateTimeImmutable $until): int
    {
        return (int) ceil(Elapsed::secondsBetween($this->clock->now(), $until));
    }

    private function after(string $interval): \DateTimeImmutable
    {
        return $this->clock->now()->add(new \DateInterval($interval));
    }
}
