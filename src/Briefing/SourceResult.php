<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Model\SourcePayload;

final readonly class SourceResult
{
    private function __construct(
        public SourceName $source,
        public SourceStatus $status,
        public ?SourcePayload $payload,
        public ?FailureKind $failureKind,
        public ?int $httpStatus,
        public ?\DateTimeImmutable $retryAt,
        public ?FailureCause $cause,
    ) {
    }

    public static function ok(SourceName $source, SourcePayload $payload): self
    {
        return new self($source, SourceStatus::Ok, $payload, null, null, null, null);
    }

    public static function notApplicable(SourceName $source): self
    {
        return new self($source, SourceStatus::NotApplicable, null, null, null, null, null);
    }

    /**
     * @phpstan-assert-if-true !null $this->payload
     */
    public function hasData(): bool
    {
        return $this->payload instanceof SourcePayload;
    }

    public static function failed(SourceName $source, FailureKind $kind, ?int $httpStatus = null, ?\DateTimeImmutable $retryAt = null, ?FailureCause $cause = null): self
    {
        return new self($source, SourceStatus::Failed, null, $kind, $httpStatus, $retryAt, $cause);
    }
}
