<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\BriefingAggregator;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Model\SourcePayload;
use App\Briefing\RefreshPolicy;
use App\Briefing\Repository\SourceSnapshotRepository;
use App\Briefing\Source\ConcurrentFetcher;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\Source;
use App\Briefing\SourceFailure;
use App\Briefing\SourceFailureLog;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Briefing\SourceStatus;
use App\Tests\Support\Briefing\CallJournal;
use App\Tests\Support\Briefing\JournalingSource;
use App\Tests\Support\Briefing\SourcePayloads;
use App\Tests\Support\Briefing\StubSource;
use App\Tests\Support\InteractsWithTestServices;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(SourceStatus::NotApplicable, $briefing->resultFor(SourceName::Apod)->status);
        self::assertSame(SourceStatus::Ok, $briefing->resultFor(SourceName::OnThisDay)->status);
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
        self::assertEquals(SourceResult::ok(SourceName::OnThisDay, SourcePayloads::onThisDayFeed()), $briefing->resultFor(SourceName::OnThisDay));
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
        self::assertEquals(SourceResult::ok(SourceName::OnThisDay, SourcePayloads::onThisDayFeed()), $briefing->resultFor(SourceName::OnThisDay));
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
        self::assertTrue($briefing->resultFor(SourceName::Weather)->hasData());
    }

    public function testLeavesOutStoredDataOfASourceThatNoLongerCoversTheDate(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::pendingFetch())], $clock)->briefingFor(self::day(), self::warsaw());

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, self::unrequested(), false)], $clock)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertSame(SourceStatus::NotApplicable, $briefing->resultFor(SourceName::OnThisDay)->status);
    }

    public function testKeepsTheFirstFailureWhenTheSourceIsNotAskedAgain(): void
    {
        // Given
        $clock = new MockClock(self::NOW);
        self::aggregator([new StubSource(SourceName::OnThisDay, static fn (): PendingFetch => self::failingFetch())], $clock)->briefingFor(self::day(), self::warsaw());

        // When
        $briefing = self::aggregator([new StubSource(SourceName::OnThisDay, self::unrequested())], $clock)->briefingFor(self::day(), self::warsaw());

        // Then
        self::assertEquals(new SourceFailure(FailureKind::Unavailable), $briefing->resultFor(SourceName::OnThisDay)->failure);
    }

    /**
     * @param list<Source> $sources
     */
    private static function aggregator(array $sources, MockClock $clock): BriefingAggregator
    {
        return new BriefingAggregator($sources, new ConcurrentFetcher(new MockHttpClient(), $clock, new NullLogger(), 3.5), $clock, new SourceFailureLog(new NullLogger()), self::testService(SourceSnapshotRepository::class), new RefreshPolicy());
    }

    private static function pendingFetch(SourceName $source = SourceName::OnThisDay): PendingFetch
    {
        $response = new MockHttpClient(new MockResponse('{}'))->request(Request::METHOD_GET, self::URL);

        return new PendingFetch([$response], static fn (): SourcePayload => SourcePayloads::of($source), new MockClock(self::NOW));
    }

    private static function failingFetch(): PendingFetch
    {
        $response = new MockHttpClient(new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]))->request(Request::METHOD_GET, self::URL);

        return new PendingFetch([$response], static fn (): SourcePayload => SourcePayloads::onThisDayFeed(), new MockClock(self::NOW));
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
