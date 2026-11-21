<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Shared\Logging\LogContextKey;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

#[WithMonologChannel(self::CHANNEL)]
final readonly class Prefetcher
{
    private const string CHANNEL = 'briefing';
    private const string SUMMARY = 'Prefetch finished: {dates} dates, {failures} failures, {problems} problems';

    public function __construct(
        private BriefingAggregator $aggregator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return \Generator<int, BriefingResult, mixed, void>
     */
    public function prefetch(DateRange $range, Location $location, int $delayMs = 0): \Generator
    {
        $tally = new PrefetchTally($this->clock->now());

        try {
            yield from $this->briefingsFor($range, $location, $delayMs, $tally);
        } finally {
            $this->logSummary($range, $tally);
        }
    }

    /**
     * @return \Generator<int, BriefingResult, mixed, void>
     */
    private function briefingsFor(DateRange $range, Location $location, int $delayMs, PrefetchTally $tally): \Generator
    {
        foreach ($range as $index => $date) {
            $this->pauseAfterAFetch($tally, $delayMs);
            $briefing = $this->aggregator->briefingFor($date, $location);
            $tally->add($briefing);

            yield $index => $briefing;
        }
    }

    private function pauseAfterAFetch(PrefetchTally $tally, int $delayMs): void
    {
        if ($tally->lastDateFetched) {
            $this->clock->sleep($delayMs / Elapsed::MILLISECONDS_PER_SECOND);
        }
    }

    private function logSummary(DateRange $range, PrefetchTally $tally): void
    {
        $this->logger->info(self::SUMMARY, [
            LogContextKey::From->value => $range->from->format(BriefingDay::FORMAT),
            LogContextKey::To->value => $range->to->format(BriefingDay::FORMAT),
            LogContextKey::Dates->value => $tally->dates,
            LogContextKey::Failures->value => $tally->failures,
            LogContextKey::Problems->value => $tally->problems,
            LogContextKey::DurationMs->value => Elapsed::millisecondsBetween($tally->startedAt, $this->clock->now()),
        ]);
    }
}
