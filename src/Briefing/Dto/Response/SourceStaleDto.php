<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class SourceStaleDto implements SourceStatusDto
{
    private const bool STALE = true;

    public SourceStatusValue $status;

    public \DateTimeImmutable $fetchedAt;

    public bool $stale;

    public function __construct(
        \DateTimeImmutable $fetchedAt,
        public SourceError $error,
        public \DateTimeImmutable $lastAttemptAt,
    ) {
        $this->status = SourceStatusValue::Ok;
        $this->fetchedAt = $fetchedAt;
        $this->stale = self::STALE;
    }
}
