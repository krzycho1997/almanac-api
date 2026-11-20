<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Repository\SourceSnapshotRepository;
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
        private SourceSnapshotRepository $snapshots,
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
        $stored = $this->snapshots->findForScopes(array_values($scopes));
        $due = $this->dueForFetch($covering, $stored, $date, $now);
        $settled = $this->fetcher->settleAll(self::startAll($due, $date, $location));
        $this->recordFailures($settled, $date);
        $current = $this->savedAndReread($settled, $scopes, $stored, $now);

        return new BriefingResult($date, $location, $this->inSourceOrder($current), $now);
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
     * @return list<Source>
     */
    private function dueForFetch(array $sources, array $stored, \DateTimeImmutable $date, \DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $sources,
            fn (Source $source): bool => $this->policy->decide(($stored[$source->name()->value] ?? null)?->state(), $source->refreshAfter(), $date, $now)->fetches(),
        ));
    }

    /**
     * @param list<SettledFetch>                          $settled
     * @param array<value-of<SourceName>, SnapshotScope>  $scopes
     * @param array<value-of<SourceName>, StoredSnapshot> $stored
     *
     * @return array<value-of<SourceName>, StoredSnapshot>
     */
    private function savedAndReread(array $settled, array $scopes, array $stored, \DateTimeImmutable $now): array
    {
        if ([] === $settled) {
            return $stored;
        }

        $this->snapshots->saveResults(array_map(static fn (SettledFetch $fetch): SourceResult => $fetch->result, $settled), $scopes, $now);

        return $this->snapshots->findForScopes(array_values($scopes));
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
     * @param array<value-of<SourceName>, StoredSnapshot> $stored
     *
     * @return list<SourceResult>
     */
    private function inSourceOrder(array $stored): array
    {
        return array_map(static fn (Source $source): SourceResult => self::resultOf($source->name(), $stored), $this->sources);
    }

    /**
     * @param array<value-of<SourceName>, StoredSnapshot> $stored
     */
    private static function resultOf(SourceName $source, array $stored): SourceResult
    {
        $snapshot = $stored[$source->value] ?? null;

        if ($snapshot instanceof StoredSnapshot) {
            return $snapshot->result();
        }

        return SourceResult::notApplicable($source);
    }

    /**
     * @param list<SettledFetch> $settled
     */
    private function recordFailures(array $settled, \DateTimeImmutable $date): void
    {
        foreach ($settled as $fetch) {
            $this->failureLog->record($fetch->result, $date, $fetch->durationMs);
        }
    }
}
