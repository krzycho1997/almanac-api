<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class SourceOkDto implements SourceStatusDto
{
    public SourceStatusValue $status;

    public function __construct(
        public \DateTimeImmutable $fetchedAt,
        public bool $stale,
    ) {
        $this->status = SourceStatusValue::Ok;
    }
}
