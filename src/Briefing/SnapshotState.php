<?php

declare(strict_types=1);

namespace App\Briefing;

final readonly class SnapshotState
{
    private function __construct(
        public \DateTimeImmutable $lastAttemptAt,
        public bool $lastAttemptFailed,
        public bool $hasPayload,
    ) {
    }

    public static function fetchedAt(\DateTimeImmutable $fetchedAt): self
    {
        return new self($fetchedAt, false, true);
    }

    public static function failedAt(\DateTimeImmutable $lastAttemptAt, bool $hasPayload): self
    {
        return new self($lastAttemptAt, true, $hasPayload);
    }
}
