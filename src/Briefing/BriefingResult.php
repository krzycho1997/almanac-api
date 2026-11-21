<?php

declare(strict_types=1);

namespace App\Briefing;

final readonly class BriefingResult
{
    /**
     * @param list<SourceEntry>  $entries
     * @param list<SourceResult> $results
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public Location $location,
        private array $entries,
        private array $results,
    ) {
    }

    /**
     * @return list<SourceResult>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->results, static fn (SourceResult $result): bool => $result->hasFailed()));
    }

    /**
     * @return list<BriefingProblem>
     */
    public function problems(): array
    {
        return [...$this->failedNow(), ...$this->inPause()];
    }

    public function hasProblems(): bool
    {
        return [] !== $this->problems();
    }

    public function hasAnyData(): bool
    {
        return array_any($this->entries, static fn (SourceEntry $entry): bool => $entry->hasData());
    }

    public function hasFetched(): bool
    {
        return array_any($this->results, static fn (SourceResult $result): bool => $result->calledUpstream());
    }

    public function entryFor(SourceName $source): SourceEntry
    {
        return array_find($this->entries, static fn (SourceEntry $entry): bool => $source === $entry->source)
            ?? throw new \LogicException("The briefing has no result for {$source->value}.");
    }

    /**
     * @return list<SourceName>
     */
    public function missing(): array
    {
        $missing = array_filter($this->entries, static fn (SourceEntry $entry): bool => false === $entry->hasData());

        return array_values(array_map(static fn (SourceEntry $entry): SourceName => $entry->source, $missing));
    }

    /**
     * @return list<BriefingProblem>
     */
    private function failedNow(): array
    {
        $problems = [];

        foreach ($this->results as $result) {
            if ($result->hasFailed()) {
                $problems[] = new BriefingProblem($result->source, $result->failure, self::timingOf($result));
            }
        }

        return $problems;
    }

    /**
     * @return list<BriefingProblem>
     */
    private function inPause(): array
    {
        $problems = [];

        foreach ($this->entries as $entry) {
            if ($this->isInPause($entry)) {
                $problems[] = new BriefingProblem($entry->source, $entry->lastFailure, ProblemTiming::InPause);
            }
        }

        return $problems;
    }

    /**
     * @phpstan-assert-if-true !null $entry->lastFailure
     */
    private function isInPause(SourceEntry $entry): bool
    {
        if ($entry->isMissingAfterFailure()) {
            return false === array_any($this->failures(), static fn (SourceResult $result): bool => $entry->source === $result->source);
        }

        return false;
    }

    private static function timingOf(SourceResult $result): ProblemTiming
    {
        if ($result->calledUpstream()) {
            return ProblemTiming::FailedNow;
        }

        return ProblemTiming::Skipped;
    }
}
