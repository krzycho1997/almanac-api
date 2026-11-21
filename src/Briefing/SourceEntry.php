<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Model\SourcePayload;

final readonly class SourceEntry
{
    private function __construct(
        public SourceName $source,
        public ?SourcePayload $payload,
        public ?\DateTimeImmutable $fetchedAt,
        public ?SourceFailure $lastFailure,
        public ?\DateTimeImmutable $lastAttemptAt,
    ) {
    }

    public static function fresh(SourceName $source, SourcePayload $payload, \DateTimeImmutable $fetchedAt): self
    {
        return new self($source, $payload, $fetchedAt, null, $fetchedAt);
    }

    public static function stale(SourceName $source, SourcePayload $payload, \DateTimeImmutable $fetchedAt, SourceFailure $lastFailure, \DateTimeImmutable $lastAttemptAt): self
    {
        return new self($source, $payload, $fetchedAt, $lastFailure, $lastAttemptAt);
    }

    public static function missing(SourceName $source, SourceFailure $lastFailure, \DateTimeImmutable $lastAttemptAt): self
    {
        return new self($source, null, null, $lastFailure, $lastAttemptAt);
    }

    public static function notApplicable(SourceName $source): self
    {
        return new self($source, null, null, null, null);
    }

    public function refusedAt(\DateTimeImmutable $refusedAt): self
    {
        $failure = new SourceFailure(FailureKind::CircuitOpen);

        if ($this->hasData()) {
            return self::stale($this->source, $this->payload, $this->fetchedAt, $failure, $refusedAt);
        }

        return self::missing($this->source, $failure, $refusedAt);
    }

    /**
     * @phpstan-assert-if-true !null $this->payload
     * @phpstan-assert-if-true !null $this->fetchedAt
     */
    public function hasData(): bool
    {
        return $this->payload instanceof SourcePayload;
    }

    public function isMissingAfterOutage(): bool
    {
        if ($this->isMissingAfterFailure()) {
            return $this->lastFailure->kind->countsAsOutage();
        }

        return false;
    }

    /**
     * @phpstan-assert-if-true !null $this->lastFailure
     */
    public function isMissingAfterFailure(): bool
    {
        if ($this->hasData()) {
            return false;
        }

        return $this->hasFailed();
    }

    /**
     * @phpstan-assert-if-true !null $this->lastFailure
     * @phpstan-assert-if-true !null $this->lastAttemptAt
     */
    public function hasFailed(): bool
    {
        return $this->lastFailure instanceof SourceFailure;
    }
}
