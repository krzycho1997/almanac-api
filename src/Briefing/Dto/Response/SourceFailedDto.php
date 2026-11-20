<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class SourceFailedDto implements SourceStatusDto
{
    public SourceStatusValue $status;

    public function __construct(
        public SourceError $error,
        public \DateTimeImmutable $lastAttemptAt,
    ) {
        $this->status = SourceStatusValue::Failed;
    }
}
