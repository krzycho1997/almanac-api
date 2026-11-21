<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\BriefingResult;
use App\Briefing\CircuitBreaker\CircuitBreakerStore;
use App\Briefing\DateRange;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Prefetcher;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\InteractsWithLogs;
use App\Tests\Support\InteractsWithTestServices;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;

final class PrefetcherTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLogs;
    use InteractsWithTestServices;

    private const string NOW = '2026-11-20 12:00:00 UTC';
    private const string SUMMARY_START = 'Prefetch finished:';

    protected function setUp(): void
    {
        parent::setUp();

        self::mockTime(new \DateTimeImmutable(self::NOW));
    }

    public function testYieldsOneBriefingPerDateWithTheFailuresOfThisRun(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-15')
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-16'));

        // When
        $briefings = iterator_to_array(self::prefetcher()->prefetch(self::range('2024-03-15', '2024-03-16'), self::warsaw()), false);

        // Then
        self::assertSame(['2024-03-15', '2024-03-16'], array_map(static fn (BriefingResult $briefing): string => $briefing->date->format('Y-m-d'), $briefings));
        self::assertEquals([SourceResult::failed(SourceName::Apod, FailureKind::Unavailable, Response::HTTP_SERVICE_UNAVAILABLE)], $briefings[0]->failures());
        self::assertEquals([SourceResult::failed(SourceName::Apod, FailureKind::Unavailable, Response::HTTP_SERVICE_UNAVAILABLE)], $briefings[1]->failures());
        self::assertSame([], self::upstream()->unserved());
    }

    public function testLogsOneSummaryOnlyAfterTheLastDate(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-15')
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-16'));
        $briefings = self::prefetcher()->prefetch(self::range('2024-03-15', '2024-03-16'), self::warsaw(), 1500);

        // When
        $briefings->current();
        $beforeTheEnd = self::summaries();
        iterator_to_array($briefings, false);

        // Then
        self::assertSame([], $beforeTheEnd);
        $summaries = self::summaries();
        self::assertCount(1, $summaries);
        self::assertSame(Level::Info, $summaries[0]->level);
        self::assertSame(['from' => '2024-03-15', 'to' => '2024-03-16', 'dates' => 2, 'failures' => 2, 'problems' => 2, 'durationMs' => 1500], $summaries[0]->context);
    }

    public function testPausesOnlyBetweenDates(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-15')
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-16'));

        // When
        $briefings = iterator_to_array(self::prefetcher()->prefetch(self::range('2024-03-15', '2024-03-16'), self::warsaw(), 2000), false);

        // Then
        self::assertSame(['2026-11-20 12:00:00', '2026-11-20 12:00:02'], array_map(static fn (BriefingResult $briefing): string => $briefing->entryFor(SourceName::Weather)->fetchedAt?->format('Y-m-d H:i:s') ?? '', $briefings));
        self::assertEquals(new \DateTimeImmutable('2026-11-20 12:00:02 UTC'), Clock::get()->now());
    }

    public function testDoesNotPauseAfterADateThatFetchedNothing(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->fixture('apod/passthrough-2024-03-15.json')->onThisDayFrom('03-15')->archiveOn('2024-03-15'));
        iterator_to_array(self::prefetcher()->prefetch(self::range('2024-03-15', '2024-03-15'), self::warsaw()), false);
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-16'));

        // When
        $briefings = iterator_to_array(self::prefetcher()->prefetch(self::range('2024-03-15', '2024-03-16'), self::warsaw(), 2000), false);

        // Then
        self::assertSame([false, true], array_map(static fn (BriefingResult $briefing): bool => $briefing->hasFetched(), $briefings));
        self::assertEquals(new \DateTimeImmutable(self::NOW), Clock::get()->now());
        self::assertSame([], self::upstream()->unserved());
    }

    public function testDoesNotPauseAfterADateWhoseSourcesWereAllSkippedByOpenCircuits(): void
    {
        // Given
        foreach (SourceName::cases() as $source) {
            self::testService(CircuitBreakerStore::class)->open($source->value, new \DateTimeImmutable(self::NOW)->modify('+1 minute'));
        }
        self::upstream()->respondWith(UpstreamMock::create());

        // When
        $briefings = iterator_to_array(self::prefetcher()->prefetch(self::range('2024-03-15', '2024-03-16'), self::warsaw(), 2000), false);

        // Then
        self::assertSame([[false, 3], [false, 3]], array_map(static fn (BriefingResult $briefing): array => [$briefing->hasFetched(), \count($briefing->failures())], $briefings));
        self::assertEquals(new \DateTimeImmutable(self::NOW), Clock::get()->now());
        self::assertSame([], self::upstream()->served());
        self::assertSame(['failures' => 0, 'problems' => 6], array_intersect_key(self::summaries()[0]->context, ['failures' => true, 'problems' => true]));
    }

    public function testLogsTheDatesDoneWhenTheCallerStopsEarly(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-15'));
        $briefings = self::prefetcher()->prefetch(self::range('2024-03-15', '2024-03-16'), self::warsaw());

        // When
        $briefings->current();
        unset($briefings);

        // Then
        $summaries = self::summaries();
        self::assertCount(1, $summaries);
        self::assertSame(['from' => '2024-03-15', 'to' => '2024-03-16', 'dates' => 1, 'failures' => 1, 'problems' => 1, 'durationMs' => 0], $summaries[0]->context);
    }

    public function testCountsEveryFailedSourceInTheSummary(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')
            ->archive()->status(Response::HTTP_SERVICE_UNAVAILABLE)->archive()->status(Response::HTTP_SERVICE_UNAVAILABLE));

        // When
        iterator_to_array(self::prefetcher()->prefetch(self::range('2024-03-15', '2024-03-15'), self::warsaw()), false);

        // Then
        self::assertSame(2, self::summaries()[0]->context['failures'] ?? null);
    }

    public function testCountsNoNewFailureForASourceInItsPauseButStillAProblem(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-15'));
        iterator_to_array(self::prefetcher()->prefetch(self::range('2024-03-15', '2024-03-15'), self::warsaw()), false);

        // When
        $again = iterator_to_array(self::prefetcher()->prefetch(self::range('2024-03-15', '2024-03-15'), self::warsaw()), false);

        // Then
        self::assertSame([], $again[0]->failures());
        self::assertTrue($again[0]->hasProblems());
        self::assertSame(['failures' => 0, 'problems' => 1], array_intersect_key(self::summaries()[1]->context, ['failures' => true, 'problems' => true]));
    }

    private static function prefetcher(): Prefetcher
    {
        return self::testService(Prefetcher::class);
    }

    private static function upstream(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }

    private static function range(string $from, string $to): DateRange
    {
        return DateRange::between(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    private static function warsaw(): Location
    {
        return Location::fromCoordinates(52.23, 21.01);
    }

    /**
     * @return list<LogRecord>
     */
    private static function summaries(): array
    {
        return array_values(array_filter(self::recordsOf('briefing'), static fn (LogRecord $record): bool => str_starts_with($record->message, self::SUMMARY_START)));
    }
}
