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

    public function result(): SourceResult
    {
        if ($this->payload instanceof SourcePayload) {
            return SourceResult::ok($this->source, $this->payload);
        }

        return SourceResult::failed($this->source, $this->lastError ?? FailureKind::InvalidPayload);
    }
}
