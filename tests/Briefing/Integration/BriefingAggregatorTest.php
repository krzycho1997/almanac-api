<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\BriefingAggregator;
use App\Briefing\BriefingMetrics;
use App\Briefing\CircuitBreaker\CircuitBreaker;
use App\Briefing\CircuitBreaker\CircuitLog;
use App\Briefing\CircuitBreaker\CircuitPermit;
use App\Briefing\CircuitBreaker\CircuitState;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Model\SourcePayload;
use App\Briefing\RefreshPolicy;
use App\Briefing\Repository\SourceSnapshotRepository;
use App\Briefing\SnapshotRefresher;
use App\Briefing\SnapshotScope;
use App\Briefing\Source\ConcurrentFetcher;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\Source;
use App\Briefing\SourceEntry;
use App\Briefing\SourceFailure;
use App\Briefing\SourceFailureLog;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Tests\Support\Briefing\CallJournal;
use App\Tests\Support\Briefing\CircuitBreaker\InMemoryCircuitBreakerStore;
use App\Tests\Support\Briefing\JournalingSource;
use App\Tests\Support\Briefing\OnThisDayPayloads;
use App\Tests\Support\Briefing\SourcePayloads;
use App\Tests\Support\Briefing\StubSource;
use App\Tests\Support\InteractsWithTestServices;
use App\Tests\Support\Metrics\RecordingMetrics;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BriefingAggregatorTest extends KernelTestCase
{
    use InteractsWithTestServices;

    private const string NOW = '2026-11-20 12:00:00';
    private const string REFUSES_EVERY_WRITE = 'simulated_database_refusing_every_write';
    private const string OPENED_UNTIL = '2026-11-20 12:01:00';
    private const string DAY = '2026-11-20';
    private const string URL = 'https://upstream.test/resource';

    public function testStartsEveryRequestBeforeSettlingAny(): void
    {
        // Given
        $journal = new CallJournal();
        $clock = new MockClock(self::NOW);
        $aggregator = self::aggregator([new JournalingSource(SourceName::Apod, $journal, $clock), new JournalingSource(SourceName::Weather, $journal, $clock)], $clock);

        // When
        $aggregator->briefingFor(new \DateTimeImmutable(self::DAY), Location::fromCoordinates(52.23, 21.01));

        // Then
        self::assertSame(['request apod', 'request weather', 'settle apod', 'settle weather'], $journal->entries());
    }

    public function testKeepsTheOrderOfSourcesWithThoseThatDoNotCoverTheDate(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $sources = [
            new StubSource(SourceName::Apod, static fn (): PendingFetch => throw new \LogicException('An uncovered source must not be requested.'), false),
            new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::pendingFetch()),
            new StubSource(SourceName::Weather, static fn (): PendingFetch => throw new \LogicException('An uncovered source must not be requested.'), false),
        ];

        // When
        $briefing = self::aggregator($sources, $clock)->briefingFor(new \DateTimeImmutable(self::DAY), Location::fromCoordinates(52.23, 21.01));

        // Then
        self::assertSame([SourceName::Apod, SourceName::Weather], $briefing->missing());
        self::assertEquals(SourceEntry::notApplicable(SourceName::Apod), $briefing->entryFor(SourceName::Apod));
        self::assertEquals(SourceEntry::fresh(SourceName::OnThisDay, OnThisDayPayloads::feed(), $clock->now()), $briefing->entryFor(SourceName::OnThisDay));
    }

    /**
     * @param list<SourceName> $given
     */
    #[DataProvider('orders')]
    public function testListsTheSourcesInTheOrderOfTheirNames(array $given): void
    {
        // Given
        $uncovered = static fn (): PendingFetch => throw new \LogicException('An uncovered source must not be requested.');
        $sources = array_map(static fn (SourceName $name): StubSource => new StubSource($name, $uncovered, false), $given);

        // When
        $briefing = self::aggregator($sources, new MockClock(self::NOW))->briefingFor(new \DateTimeImmutable(self::DAY), Location::fromCoordinates(52.23, 21.01));

        // Then
        self::assertSame(SourceName::cases(), $briefing->missing());
    }

    /**
     * @return iterable<string, array{list<SourceName>}>
     */
    public static function orders(): iterable
    {
        [$apod, $onThisDay, $weather] = SourceName::cases();

        foreach ([[$apod, $onThisDay, $weather], [$apod, $weather, $onThisDay], [$onThisDay, $apod, $weather], [$onThisDay, $weather, $apod], [$weather, $apod, $onThisDay], [$weather, $onThisDay, $apod]] as $order) {
            yield implode(' ', array_map(static fn (SourceName $name): string => $name->value, $order)) => [$order];
        }
    }

    public function testCancelsTheStartedRequestsWhenAnotherCannotStart(): void
    {
        // Given
        $started = self::pendingFetch();
        $sources = [
            new StubSource(SourceName::Apod, static fn (): PendingFetch => $started),
            new StubSource(SourceName::Weather, static fn (): PendingFetch => throw new \RuntimeException('The request cannot start.')),
        ];

        // Then / When
        try {
            self::aggregator($sources, new MockClock(self::NOW))->briefingFor(new \DateTimeImmutable(self::DAY), Location::fromCoordinates(52.23, 21.01));
            self::fail('The failure to start a request must reach the caller.');
        } catch (\RuntimeException) {
            self::assertTrue($started->responses()[0]->getInfo('canceled'));
        }
    }

    public function testServesStoredDataWithoutRequestingTheSourceAgain(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::pendingFetch())], $clock)->briefingFor(self::day(), self::warsaw());

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, self::unrequested())], $clock)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertEquals(SourceEntry::fresh(SourceName::OnThisDay, OnThisDayPayloads::feed(), $clock->now()), $briefing->entryFor(SourceName::OnThisDay));
    }

    #[DataProvider('laterRequests')]
    public function testRequestsASourceAgainOnlyWhenThePolicySays(string $refreshAfter, bool $firstFails, string $later, bool $requested): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $interval = new \DateInterval($refreshAfter);
        $first = $firstFails ? self::failingFetch(...) : self::pendingFetch(...);
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => $first(), true, $interval)], $clock)->briefingFor(self::day(), self::warsaw());
        $clock->modify($later);
        $requests = 0;
        $counted = static function () use (&$requests): PendingFetch {
            ++$requests;

            return self::pendingFetch();
        };

        // When
        self::aggregator([new StubSource(SourceName::OnThisDay, $counted, true, $interval)], $clock)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertSame($requested ? 1 : 0, $requests);
    }

    /**
     * @return iterable<string, array{string, bool, string, bool}>
     */
    public static function laterRequests(): iterable
    {
        yield 'fresh data within its interval' => ['PT1H', false, '+59 minutes', false];
        yield 'data as old as its interval' => ['PT1H', false, '+1 hour', true];
        yield 'a failure within the pause' => ['PT1H', true, '+9 minutes', false];
        yield 'a failure after the pause' => ['PT1H', true, '+10 minutes', true];
    }

    public function testServesWhatARefreshBroughtBack(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::failingFetch())], $clock)->briefingFor(self::day(), self::warsaw());
        $clock->modify('+10 minutes');

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::pendingFetch())], $clock)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertEquals(SourceEntry::fresh(SourceName::OnThisDay, OnThisDayPayloads::feed(), $clock->now()), $briefing->entryFor(SourceName::OnThisDay));
    }

    public function testNeverRequestsAStoredPastDayAgain(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $yesterday = new \DateTimeImmutable(self::DAY)->modify('-1 day');
        self::aggregator([new StubSource(SourceName::Weather, static fn (): PendingFetch => self::pendingFetch(SourceName::Weather), true, new \DateInterval('PT1H'))], $clock)->briefingFor($yesterday, self::warsaw());
        $clock->modify('+2 hours');

        // When
        $briefing = self::aggregator([new StubSource(SourceName::Weather, self::unrequested(), true, new \DateInterval('PT1H'))], $clock)->briefingFor($yesterday, self::warsaw());

        // Then
        self::assertTrue($briefing->entryFor(SourceName::Weather)->hasData());
    }

    public function testLeavesOutStoredDataOfASourceThatNoLongerCoversTheDate(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::pendingFetch())], $clock)->briefingFor(self::day(), self::warsaw());

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, self::unrequested(), false)], $clock)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertEquals(SourceEntry::notApplicable(SourceName::OnThisDay), $briefing->entryFor(SourceName::OnThisDay));
    }

    public function testLogsAFailureAsStaleWhenAConcurrentSuccessStoredData(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $logs = new TestHandler();
        $concurrentSuccessInTheSameSecond = static function () use ($clock): PendingFetch {
            self::testService(SourceSnapshotRepository::class)->saveResult(SourceResult::ok(SourceName::OnThisDay, OnThisDayPayloads::feed()), SnapshotScope::undated(SourceName::OnThisDay, '11-20'), $clock->now());

            return self::failingFetch();
        };

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, $concurrentSuccessInTheSameSecond)], $clock, new Logger('briefing', [$logs]))->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertEquals(SourceEntry::fresh(SourceName::OnThisDay, OnThisDayPayloads::feed(), $clock->now()), $briefing->entryFor(SourceName::OnThisDay));
        self::assertSame('stale', $logs->getRecords()[0]->context['outcome'] ?? null);
    }

    public function testStillLogsTheFailuresWhenTheyCannotBeSaved(): void
    {
        // Given
        $logs = new TestHandler();
        self::refuseEveryWriteOfSnapshots();

        // When
        try {
            self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::failingFetch(), scope: SnapshotScope::undated(SourceName::OnThisDay, '11-20'))], new MockClock(self::NOW), new Logger('briefing', [$logs]))->briefingFor(self::day(), self::warsaw());
            self::fail('A failed save must reach the caller.');
        } catch (\Doctrine\DBAL\Exception) {
        }

        // Then
        self::assertSame(['on_this_day', 'missing'], [$logs->getRecords()[0]->context['source'] ?? null, $logs->getRecords()[0]->context['outcome'] ?? null]);
    }

    public function testLogsAFailureWithoutStoredDataAsMissing(): void
    {
        // Given
        $logs = new TestHandler();

        // When
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::failingFetch())], new MockClock(self::NOW), new Logger('briefing', [$logs]))->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertSame('missing', $logs->getRecords()[0]->context['outcome'] ?? null);
    }

    public function testServesFinalDataAsFreshEvenAfterALaterFailedAttempt(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::pendingFetch())], $clock)->briefingFor(self::day(), self::warsaw());
        self::testService(SourceSnapshotRepository::class)->saveResult(SourceResult::failed(SourceName::OnThisDay, FailureKind::Unavailable), SnapshotScope::undated(SourceName::OnThisDay, '11-20'), $clock->now()->modify('+1 second'));

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, self::unrequested())], $clock)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertEquals(SourceEntry::fresh(SourceName::OnThisDay, OnThisDayPayloads::feed(), $clock->now()), $briefing->entryFor(SourceName::OnThisDay));
    }

    public function testTellsTheFailuresOfThisRunFromThoseOfAnEarlierOne(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $first = self::aggregator([new StubSource(SourceName::Apod, static fn (): PendingFetch => self::pendingFetch(SourceName::Apod)), new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::failingFetch()), new StubSource(SourceName::Weather, static fn (): PendingFetch => self::pendingFetch(SourceName::Weather))], $clock)->briefingFor(self::day(), self::warsaw());

        // When
        $second = self::aggregator([new StubSource(SourceName::OnThisDay, self::unrequested())], $clock)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertSame([[SourceName::OnThisDay], []], [array_map(static fn (SourceResult $failure): SourceName => $failure->source, $first->failures()), $second->failures()]);
        self::assertSame([true, true], [$first->hasProblems(), $second->hasProblems()]);
    }

    public function testKeepsTheFirstFailureWhenTheSourceIsNotAskedAgain(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::failingFetch())], $clock)->briefingFor(self::day(), self::warsaw());

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, self::unrequested())], $clock)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertEquals(new SourceFailure(FailureKind::Unavailable), $briefing->entryFor(SourceName::OnThisDay)->lastFailure);
    }

    public function testOpensTheCircuitAfterThreeOutagesAndThenSkipsTheSource(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $breaker = self::breaker($clock);
        self::openCircuitOfOnThisDay($clock, $breaker);
        $requests = 0;
        $logs = new TestHandler();

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, self::counting($requests, self::pendingFetch(...)))], $clock, new Logger('briefing', [$logs]), $breaker)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertSame(CircuitState::Open, $breaker->state(SourceName::OnThisDay));
        self::assertSame(0, $requests);
        self::assertEquals([SourceResult::circuitOpen(SourceName::OnThisDay, new \DateTimeImmutable(self::OPENED_UNTIL))], $briefing->failures());
        self::assertEquals(SourceEntry::missing(SourceName::OnThisDay, new SourceFailure(FailureKind::CircuitOpen), $clock->now()), $briefing->entryFor(SourceName::OnThisDay));
        self::assertFalse($briefing->hasFetched());
        self::assertSame([], $logs->getRecords());
        self::assertSame([], self::testService(SnapshotRefresher::class)->storedFor([SnapshotScope::undated(SourceName::OnThisDay, '11-20')]));
    }

    public function testServesStoredDataAsStaleWithoutSavingTheRefusal(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $breaker = self::breaker($clock);
        $interval = new \DateInterval('PT1H');
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::pendingFetch(), true, $interval)], $clock, breaker: $breaker)->briefingFor(self::day(), self::warsaw());
        $fetchedAt = $clock->now();
        $clock->modify('+1 hour');
        self::openCircuitOfOnThisDay($clock, $breaker);

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, self::unrequested(), true, $interval)], $clock, breaker: $breaker)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertEquals(SourceEntry::stale(SourceName::OnThisDay, OnThisDayPayloads::feed(), $fetchedAt, new SourceFailure(FailureKind::CircuitOpen), $clock->now()), $briefing->entryFor(SourceName::OnThisDay));
        self::assertTrue($briefing->hasAnyData());
        $stored = self::testService(SnapshotRefresher::class)->storedFor([SnapshotScope::undated(SourceName::OnThisDay, '11-20')]);
        $snapshot = $stored[SourceName::OnThisDay->value] ?? null;
        self::assertEquals([$fetchedAt, null], [$snapshot?->lastAttemptAt, $snapshot?->lastError]);
    }

    public function testLetsOneTrialThroughAfterTheOpeningAndClosesTheCircuitOnItsSuccess(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $breaker = self::breaker($clock);
        self::openCircuitOfOnThisDay($clock, $breaker);
        $clock->modify('+60 seconds');
        $requests = 0;

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, self::counting($requests, self::pendingFetch(...)))], $clock, breaker: $breaker)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertSame(1, $requests);
        self::assertSame(CircuitState::Closed, $breaker->state(SourceName::OnThisDay));
        self::assertEquals(SourceEntry::fresh(SourceName::OnThisDay, OnThisDayPayloads::feed(), $clock->now()), $briefing->entryFor(SourceName::OnThisDay));
    }

    public function testAsksTheBreakerOnlyForTheSourcesThePolicyFetches(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $breaker = self::breaker($clock);
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::pendingFetch())], $clock, breaker: $breaker)->briefingFor(self::day(), self::warsaw());
        self::openCircuitOfOnThisDay($clock, $breaker);
        $clock->modify('+60 seconds');

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, self::unrequested())], $clock, breaker: $breaker)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertSame([], $briefing->failures());
        self::assertSame(CircuitPermit::Trial, $breaker->tryAcquire(SourceName::OnThisDay));
    }

    public function testCountsTheOutagesInTheCircuitEvenWhenTheyCannotBeSaved(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $breaker = self::breaker($clock);
        self::refuseEveryWriteOfSnapshots();

        // When
        foreach (range(1, 3) as $attempt) {
            try {
                self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::failingFetch(), scope: SnapshotScope::undated(SourceName::OnThisDay, '11-20'))], $clock, breaker: $breaker)->briefingFor(self::day(), self::warsaw());
                self::fail('A failed save must reach the caller.');
            } catch (\Doctrine\DBAL\Exception) {
            }
        }

        // Then
        self::assertSame(CircuitState::Open, $breaker->state(SourceName::OnThisDay));
    }

    public function testSkipsOnlyTheSourceWhoseCircuitIsOpen(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        $breaker = self::breaker($clock);
        self::openCircuitOfOnThisDay($clock, $breaker);

        // When
        $briefing = self::aggregator([new StubSource(SourceName::Apod, static fn (): PendingFetch => self::pendingFetch(SourceName::Apod)), new StubSource(SourceName::OnThisDay, self::unrequested())], $clock, breaker: $breaker)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertTrue($briefing->entryFor(SourceName::Apod)->hasData());
        self::assertEquals([SourceResult::circuitOpen(SourceName::OnThisDay, new \DateTimeImmutable(self::OPENED_UNTIL))], $briefing->failures());
        self::assertTrue($briefing->hasFetched());
    }

    /**
     * @param list<Source> $sources
     */
    private static function aggregator(array $sources, MockClock $clock, LoggerInterface $logger = new NullLogger(), ?CircuitBreaker $breaker = null): BriefingAggregator
    {
        return new BriefingAggregator($sources, new ConcurrentFetcher(new MockHttpClient(), $clock, new NullLogger(), 3.5), $clock, new SourceFailureLog($logger), self::testService(SnapshotRefresher::class), new RefreshPolicy(), $breaker ?? self::breaker($clock), self::testService(BriefingMetrics::class));
    }

    private static function refuseEveryWriteOfSnapshots(): void
    {
        self::testService(Connection::class)->executeStatement('ALTER TABLE briefing_source_snapshot ADD CONSTRAINT '.self::REFUSES_EVERY_WRITE.' CHECK (false) NOT VALID');
    }

    private static function breaker(MockClock $clock): CircuitBreaker
    {
        return new CircuitBreaker(new InMemoryCircuitBreakerStore($clock), $clock, new CircuitLog(new NullLogger(), new RecordingMetrics()));
    }

    /**
     * @return \Closure(): PendingFetch
     */
    private static function counting(int &$requests, \Closure $fetch): \Closure
    {
        return static function () use (&$requests, $fetch): PendingFetch {
            ++$requests;

            return $fetch();
        };
    }

    private static function openCircuitOfOnThisDay(MockClock $clock, CircuitBreaker $breaker): void
    {
        foreach (['01-01', '01-02', '01-03'] as $day) {
            self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::failingFetch(), scope: SnapshotScope::undated(SourceName::OnThisDay, $day))], $clock, breaker: $breaker)->briefingFor(self::day(), self::warsaw());
        }
    }

    private static function pendingFetch(SourceName $source = SourceName::OnThisDay): PendingFetch
    {
        $response = new MockHttpClient(new MockResponse('{}'))->request(Request::METHOD_GET, self::URL);

        return new PendingFetch([$response], static fn (): SourcePayload => SourcePayloads::of($source), new MockClock(self::NOW));
    }

    private static function failingFetch(): PendingFetch
    {
        $response = new MockHttpClient(new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]))->request(Request::METHOD_GET, self::URL);

        return new PendingFetch([$response], static fn (): SourcePayload => OnThisDayPayloads::feed(), new MockClock(self::NOW));
    }

    /**
     * @return \Closure(): PendingFetch
     */
    private static function unrequested(): \Closure
    {
        return static fn (): PendingFetch => throw new \LogicException('A source with stored data must not be requested.');
    }

    private static function day(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::DAY);
    }

    private static function warsaw(): Location
    {
        return Location::fromCoordinates(52.23, 21.01);
    }
}
