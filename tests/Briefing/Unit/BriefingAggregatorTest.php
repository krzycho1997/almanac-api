<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\BriefingAggregator;
use App\Briefing\Location;
use App\Briefing\Model\SourcePayload;
use App\Briefing\Source\ConcurrentFetcher;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\Source;
use App\Briefing\SourceFailureLog;
use App\Briefing\SourceName;
use App\Briefing\SourceStatus;
use App\Tests\Support\Briefing\CallJournal;
use App\Tests\Support\Briefing\FakePayload;
use App\Tests\Support\Briefing\JournalingSource;
use App\Tests\Support\Briefing\StubSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class BriefingAggregatorTest extends TestCase
{
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

    /**
     * @param list<Source> $sources
     */
    private static function aggregator(array $sources, MockClock $clock): BriefingAggregator
    {
        return new BriefingAggregator($sources, new ConcurrentFetcher(new MockHttpClient(), $clock, new NullLogger(), 3.5), $clock, new SourceFailureLog(new NullLogger()));
    }

    private static function pendingFetch(): PendingFetch
    {
        $response = new MockHttpClient(new MockResponse('{}'))->request(Request::METHOD_GET, self::URL);

        return new PendingFetch([$response], static fn (): SourcePayload => new FakePayload([]), new MockClock(self::NOW));
    }
}
