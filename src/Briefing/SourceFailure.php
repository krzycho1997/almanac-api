<?php

declare(strict_types=1);

namespace App\Briefing;

final readonly class SourceFailure
{
    public function __construct(
        public FailureKind $kind,
        public ?int $httpStatus = null,
        public ?\DateTimeImmutable $retryAt = null,
        public ?FailureCause $cause = null,
    ) {
    }
}
