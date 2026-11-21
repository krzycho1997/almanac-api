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
        public ?SourceFailure $failure,
    ) {
    }

    public static function ok(SourceName $source, SourcePayload $payload): self
    {
        return new self($source, SourceStatus::Ok, $payload, null);
    }

    public static function notApplicable(SourceName $source): self
    {
        return new self($source, SourceStatus::NotApplicable, null, null);
    }

    public static function circuitOpen(SourceName $source, \DateTimeImmutable $until): self
    {
        return self::failed($source, FailureKind::CircuitOpen, retryAt: $until);
    }

    /**
     * @phpstan-assert-if-true !null $this->payload
     */
    public function hasData(): bool
    {
        return $this->payload instanceof SourcePayload;
    }

    /**
     * @phpstan-assert-if-true !null $this->failure
     */
    public function hasFailed(): bool
    {
        return $this->failure instanceof SourceFailure;
    }

    public function rateLimitedUntil(): ?\DateTimeImmutable
    {
        if (FailureKind::RateLimited === $this->failure?->kind) {
            return $this->failure->retryAt;
        }

        return null;
    }

    public function refusedUntil(): ?\DateTimeImmutable
    {
        if ($this->calledUpstream()) {
            return null;
        }

        return $this->failure?->retryAt;
    }

    public function calledUpstream(): bool
    {
        return FailureKind::CircuitOpen !== $this->failure?->kind;
    }

    public static function failed(SourceName $source, FailureKind $kind, ?int $httpStatus = null, ?\DateTimeImmutable $retryAt = null, ?FailureCause $cause = null): self
    {
        return new self($source, SourceStatus::Failed, null, new SourceFailure($kind, $httpStatus, $retryAt, $cause));
    }
}
