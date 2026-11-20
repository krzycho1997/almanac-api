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
        $settled = $this->fetcher->settleAll(self::startAll($this->sourcesCovering($date), $date, $location));
        $this->recordFailures($settled, $date);

        return new BriefingResult($date, $location, $this->inSourceOrder($settled), $this->clock->now());
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
     * @param list<SettledFetch> $settled
     *
     * @return list<SourceResult>
     */
    private function inSourceOrder(array $settled): array
    {
        return array_map(static fn (Source $source): SourceResult => self::resultOf($source->name(), $settled), $this->sources);
    }

    /**
     * @param list<SettledFetch> $settled
     */
    private static function resultOf(SourceName $source, array $settled): SourceResult
    {
        foreach ($settled as $fetch) {
            if ($source === $fetch->result->source) {
                return $fetch->result;
            }
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
