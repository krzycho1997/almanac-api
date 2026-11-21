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

    public function hasProblems(): bool
    {
        if ([] === $this->failures()) {
            return array_any($this->entries, static fn (SourceEntry $entry): bool => $entry->isMissingAfterFailure());
        }

        return true;
    }

    public function hasAnyData(): bool
    {
        return array_any($this->entries, static fn (SourceEntry $entry): bool => $entry->hasData());
    }

    public function hasFetched(): bool
    {
        return [] !== $this->results;
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
}
