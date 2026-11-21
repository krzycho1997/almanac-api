<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Source\ConcurrentFetcher;
use App\Briefing\Source\SettledFetch;
use App\Briefing\Source\Source;
use App\Briefing\Source\SourceFetch;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class BriefingAggregator
{
    /**
     * @var list<Source>
     */
    private array $sources;

    /**
     * @param iterable<Source> $sources
     */
    public function __construct(
        #[AutowireIterator(Source::class)]
        iterable $sources,
        private ConcurrentFetcher $fetcher,
        private ClockInterface $clock,
        private SourceFailureLog $failureLog,
        private SnapshotRefresher $refresher,
        private RefreshPolicy $policy,
    ) {
        $this->sources = self::inNameOrder([...$sources]);
    }

    /**
     * @param array<Source> $sources
     *
     * @return list<Source>
     */
    private static function inNameOrder(array $sources): array
    {
        usort($sources, static fn (Source $first, Source $second): int => self::positionOf($first) <=> self::positionOf($second));

        return $sources;
    }

    private static function positionOf(Source $source): int
    {
        return (int) array_search($source->name(), SourceName::cases(), true);
    }

    public function briefingFor(\DateTimeImmutable $date, Location $location): BriefingResult
    {
        $now = $this->clock->now();
        $covering = $this->sourcesCovering($date);
        $scopes = self::scopesOf($covering, $date, $location);
        $stored = $this->refresher->storedFor(array_values($scopes));
        $decisions = $this->decisionsFor($covering, $stored, $date, $now);
        $settled = $this->fetcher->settleAll(self::startAll(self::dueForFetch($covering, $decisions), $date, $location));
        $entries = $this->entriesAfterSaving($settled, $scopes, $stored, $decisions, $date, $now);
        $this->recordFailures($settled, $entries, $date);

        return new BriefingResult($date, $location, array_values($entries), self::resultsOf($settled));
    }

    /**
     * @param list<Source> $sources
     *
     * @return array<value-of<SourceName>, SnapshotScope>
     */
    private static function scopesOf(array $sources, \DateTimeImmutable $date, Location $location): array
    {
        $scopes = [];

        foreach ($sources as $source) {
            $scopes[$source->name()->value] = $source->scopeFor($date, $location);
        }

        return $scopes;
    }

    /**
     * @param list<Source>                                $sources
     * @param array<value-of<SourceName>, StoredSnapshot> $stored
     *
     * @return array<value-of<SourceName>, RefreshDecision>
     */
    private function decisionsFor(array $sources, array $stored, \DateTimeImmutable $date, \DateTimeImmutable $now): array
    {
        $decisions = [];

        foreach ($sources as $source) {
            $decisions[$source->name()->value] = $this->policy->decide(self::stateOf($source->name(), $stored), $source->refreshAfter(), $date, $now);
        }

        return $decisions;
    }

    /**
     * @param array<value-of<SourceName>, StoredSnapshot> $stored
     */
    private static function stateOf(SourceName $source, array $stored): ?SnapshotState
    {
        return ($stored[$source->value] ?? null)?->state();
    }

    /**
     * @param list<Source>                                 $sources
     * @param array<value-of<SourceName>, RefreshDecision> $decisions
     *
     * @return list<Source>
     */
    private static function dueForFetch(array $sources, array $decisions): array
    {
        return array_values(array_filter($sources, static fn (Source $source): bool => $decisions[$source->name()->value]->fetches()));
    }

    /**
     * @param list<SettledFetch>                          $settled
     * @param array<value-of<SourceName>, SnapshotScope>  $scopes
     * @param array<value-of<SourceName>, StoredSnapshot> $stored
     *
     * @return array<value-of<SourceName>, StoredSnapshot>
     */
    private function savedAndReread(array $settled, array $scopes, array $stored, \DateTimeImmutable $date, \DateTimeImmutable $now): array
    {
        if ([] === $settled) {
            return $stored;
        }

        return array_replace($stored, $this->refresher->saveAndReread(self::resultsOf($settled), $scopes, $date, $now));
    }

    /**
     * @param list<SettledFetch> $settled
     *
     * @return list<SourceResult>
     */
    private static function resultsOf(array $settled): array
    {
        return array_map(static fn (SettledFetch $fetch): SourceResult => $fetch->result, $settled);
    }

    /**
     * @return list<Source>
     */
    private function sourcesCovering(\DateTimeImmutable $date): array
    {
        return array_values(array_filter($this->sources, static fn (Source $source): bool => $source->client()->covers($date)));
    }

    /**
     * @param list<Source> $sources
     *
     * @return list<SourceFetch>
     */
    private static function startAll(array $sources, \DateTimeImmutable $date, Location $location): array
    {
        $fetches = [];

        try {
            foreach ($sources as $source) {
                $fetches[] = new SourceFetch($source->name(), $source->client()->request($date, $location));
            }
        } catch (\Throwable $exception) {
            self::cancelAll($fetches);

            throw $exception;
        }

        return $fetches;
    }

    /**
     * @param list<SourceFetch> $fetches
     */
    private static function cancelAll(array $fetches): void
    {
        foreach ($fetches as $fetch) {
            $fetch->pending->cancel();
        }
    }

    /**
     * @param list<SettledFetch>                           $settled
     * @param array<value-of<SourceName>, SnapshotScope>   $scopes
     * @param array<value-of<SourceName>, StoredSnapshot>  $stored
     * @param array<value-of<SourceName>, RefreshDecision> $decisions
     *
     * @return array<value-of<SourceName>, SourceEntry>
     */
    private function entriesAfterSaving(array $settled, array $scopes, array $stored, array $decisions, \DateTimeImmutable $date, \DateTimeImmutable $now): array
    {
        try {
            return $this->inSourceOrder($this->savedAndReread($settled, $scopes, $stored, $date, $now), $decisions);
        } catch (\Throwable $exception) {
            $this->recordFailures($settled, [], $date);

            throw $exception;
        }
    }

    /**
     * @param array<value-of<SourceName>, StoredSnapshot>  $stored
     * @param array<value-of<SourceName>, RefreshDecision> $decisions
     *
     * @return array<value-of<SourceName>, SourceEntry>
     */
    private function inSourceOrder(array $stored, array $decisions): array
    {
        $entries = [];

        foreach ($this->sources as $source) {
            $entries[$source->name()->value] = self::entryOf($source->name(), $stored, $decisions);
        }

        return $entries;
    }

    /**
     * @param array<value-of<SourceName>, StoredSnapshot>  $stored
     * @param array<value-of<SourceName>, RefreshDecision> $decisions
     */
    private static function entryOf(SourceName $source, array $stored, array $decisions): SourceEntry
    {
        $snapshot = $stored[$source->value] ?? null;

        if ($snapshot instanceof StoredSnapshot) {
            return $snapshot->entry(RefreshDecision::Final === ($decisions[$source->value] ?? null));
        }

        return SourceEntry::notApplicable($source);
    }

    /**
     * @param list<SettledFetch>                       $settled
     * @param array<value-of<SourceName>, SourceEntry> $entries
     */
    private function recordFailures(array $settled, array $entries, \DateTimeImmutable $date): void
    {
        foreach ($settled as $fetch) {
            $entry = $entries[$fetch->result->source->value] ?? SourceEntry::notApplicable($fetch->result->source);
            $this->failureLog->record($fetch->result, FailureOutcome::of($entry), $date, $fetch->durationMs);
        }
    }
}
