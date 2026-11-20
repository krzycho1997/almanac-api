<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Model\SourcePayload;

final readonly class StoredSnapshot
{
    public function __construct(
        public SourceName $source,
        public ?SourcePayload $payload,
        public ?\DateTimeImmutable $fetchedAt,
        public \DateTimeImmutable $lastAttemptAt,
        public ?FailureKind $lastError,
    ) {
    }

    public function state(): ?SnapshotState
    {
        if ($this->lastError instanceof FailureKind) {
            return SnapshotState::failedAt($this->lastAttemptAt, $this->payload instanceof SourcePayload);
        }

        if ($this->payload instanceof SourcePayload) {
            return SnapshotState::fetchedAt($this->lastAttemptAt);
        }

        return null;
    }

    public function entry(bool $final): SourceEntry
    {
        $failure = self::failureOf($this->lastError);

        if ($this->payload instanceof SourcePayload) {
            return $this->withData($this->payload, $failure, $final);
        }

        return SourceEntry::missing($this->source, $failure ?? self::unreadable(), $this->lastAttemptAt);
    }

    private function withData(SourcePayload $payload, ?SourceFailure $failure, bool $final): SourceEntry
    {
        $fetchedAt = $this->fetchedAt ?? throw new \UnexpectedValueException("The stored {$this->source->value} data has no fetch time.");

        if ($final) {
            return SourceEntry::fresh($this->source, $payload, $fetchedAt);
        }

        if ($failure instanceof SourceFailure) {
            return SourceEntry::stale($this->source, $payload, $fetchedAt, $failure, $this->lastAttemptAt);
        }

        return SourceEntry::fresh($this->source, $payload, $fetchedAt);
    }

    private static function unreadable(): SourceFailure
    {
        return new SourceFailure(FailureKind::InvalidPayload);
    }

    private static function failureOf(?FailureKind $kind): ?SourceFailure
    {
        if ($kind instanceof FailureKind) {
            return new SourceFailure($kind);
        }

        return null;
    }
}
