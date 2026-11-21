<?php

declare(strict_types=1);

namespace App\Briefing;

final readonly class RefreshPolicy
{
    public const string FAILURE_PAUSE = 'PT10M';

    public function __construct(
        private \DateInterval $failurePause = new \DateInterval(self::FAILURE_PAUSE),
    ) {
    }

    public function decide(?SnapshotState $state, ?\DateInterval $refreshAfter, \DateTimeImmutable $date, \DateTimeImmutable $now): RefreshDecision
    {
        if (null === $state) {
            return RefreshDecision::Fetch;
        }

        if (null === $refreshAfter) {
            return $this->forFinalData($state, $now);
        }

        if (self::isStoredPastDay($state, $date, $now)) {
            return RefreshDecision::Final;
        }

        if ($state->lastAttemptFailed) {
            return $this->afterFailure($state->lastAttemptAt, $now);
        }

        return self::afterSuccess($state->lastAttemptAt, $refreshAfter, $now);
    }

    private function forFinalData(SnapshotState $state, \DateTimeImmutable $now): RefreshDecision
    {
        if ($state->hasPayload) {
            return RefreshDecision::Final;
        }

        return $this->afterFailure($state->lastAttemptAt, $now);
    }

    private function afterFailure(\DateTimeImmutable $lastAttemptAt, \DateTimeImmutable $now): RefreshDecision
    {
        if ($lastAttemptAt->add($this->failurePause) <= $now) {
            return RefreshDecision::Fetch;
        }

        return RefreshDecision::Wait;
    }

    private static function afterSuccess(\DateTimeImmutable $lastAttemptAt, \DateInterval $refreshAfter, \DateTimeImmutable $now): RefreshDecision
    {
        if ($lastAttemptAt->add($refreshAfter) <= $now) {
            return RefreshDecision::Fetch;
        }

        return RefreshDecision::Fresh;
    }

    private static function isStoredPastDay(SnapshotState $state, \DateTimeImmutable $date, \DateTimeImmutable $now): bool
    {
        if ($state->hasPayload) {
            return BriefingDay::isBefore($date, $now);
        }

        return false;
    }
}
