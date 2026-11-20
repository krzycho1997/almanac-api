<?php

declare(strict_types=1);

namespace App\Briefing;

final readonly class BriefingResult
{
    /**
     * @param list<SourceResult> $results
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public Location $location,
        private array $results,
        public \DateTimeImmutable $checkedAt,
    ) {
    }

    public function hasAnyData(): bool
    {
        return [] !== array_filter($this->results, static fn (SourceResult $result): bool => $result->hasData());
    }

    public function resultFor(SourceName $source): SourceResult
    {
        foreach ($this->results as $result) {
            if ($source === $result->source) {
                return $result;
            }
        }

        throw new \LogicException("The briefing has no result for {$source->value}.");
    }

    /**
     * @return list<SourceName>
     */
    public function missing(): array
    {
        $missing = array_filter($this->results, static fn (SourceResult $result): bool => false === $result->hasData());

        return array_values(array_map(static fn (SourceResult $result): SourceName => $result->source, $missing));
    }
}
