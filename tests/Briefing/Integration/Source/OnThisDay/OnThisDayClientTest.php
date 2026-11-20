<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Source\OnThisDay;

use App\Briefing\Exception\PayloadDefect;
use App\Briefing\FailureCause;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Source\OnThisDay\OnThisDaySource;
use App\Briefing\SourceResult;
use App\Briefing\SourceStatus;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\InteractsWithTestServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class OnThisDayClientTest extends KernelTestCase
{
    use InteractsWithTestServices;

    private const string DATE = '2024-03-15';
    private const string HISTORY_DAY = '03-15';
    private const string FEED = 'https://en.wikipedia.org/api/rest_v1/feed/onthisday/';
    private const string PAYLOAD_ERROR = 'InvalidUpstreamPayloadException';

    #[DataProvider('dates')]
    public function testCoversEveryDate(string $date): void
    {
        // When
        $covers = self::source()->client()->covers(new \DateTimeImmutable($date));

        // Then
        self::assertTrue($covers);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dates(): iterable
    {
        yield 'day before the first picture' => ['1995-06-15'];
        yield 'leap day' => ['2024-02-29'];
        yield 'last day of the window' => ['2026-12-05'];
    }

    public function testAsksWikipediaForTheThreeListsOfTheDay(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->onThisDayFrom(self::HISTORY_DAY));

        // When
        self::fetch('2024-03-05');

        // Then
        self::assertSame(
            [self::FEED.'selected/03/05', self::FEED.'births/03/05', self::FEED.'deaths/03/05'],
            array_map(static fn (MockResponse $response): string => $response->getRequestUrl(), self::upstream()->served()),
        );
    }

    public function testMapsTheRecordedListsTrimmedToTheLimit(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->onThisDayFrom(self::HISTORY_DAY));

        // When
        $result = self::fetch(self::DATE);

        // Then
        self::assertSame(SourceStatus::Ok, $result->status);
        $feed = $result->payload;
        self::assertInstanceOf(OnThisDayFeed::class, $feed);
        self::assertSame([20, 20, 20], [\count($feed->events), \count($feed->births), \count($feed->deaths)]);
        self::assertSame([1990, 'Farzad Bazoft'], [$feed->events[0]->year, $feed->events[0]->pages[0]->title]);
        self::assertStringStartsWith('Iraqi authorities hanged Iranian freelance reporter Farzad Bazoft', $feed->events[0]->text);
        self::assertSame(['Rodri Mendoza', 'Spanish footballer', 'https://en.wikipedia.org/wiki/Rodri_Mendoza'], [$feed->births[0]->name, $feed->births[0]->description, $feed->births[0]->url]);
        self::assertEquals(new NotablePerson(-44, 'Julius Caesar', 'Roman general and dictator (100–44 BC)', 'https://en.wikipedia.org/wiki/Julius_Caesar', $feed->deaths[19]->thumbnail, 'Q1048'), $feed->deaths[19]);
        self::assertStringStartsWith('https://thumb.wikimedia.org/wikipedia/commons/thumb/6/62/', (string) $feed->deaths[19]->thumbnail);
        self::assertSame([2025, 2025, 2022, 1998, 1997, 1997, 1898, 1897, 1711, 1673, 1575, 1536, 1311, 1190, 1124, 990, 963, 493, 220, -44], array_map(static fn (NotablePerson $person): int => $person->year, $feed->deaths));
        self::assertNotContains(-44, array_map(static fn (HistoricalEvent $event): int => $event->year, $feed->events));
    }

    public function testKeepsAPersonWhosePageHasNoLinksOrPortrait(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->onThisDay()->fixture('on_this_day/selected-03-15.json')
            ->onThisDay()->json('{"births":[{"text":"Wings Hauser, American actor","year":1947,"pages":[{"normalizedtitle":"Wings Hauser","content_urls":null,"thumbnail":null}]}]}')
            ->onThisDay()->fixture('on_this_day/deaths-03-15.json'));

        // When
        $result = self::fetch(self::DATE);

        // Then
        self::assertInstanceOf(OnThisDayFeed::class, $result->payload);
        self::assertEquals([new NotablePerson(1947, 'Wings Hauser', 'American actor', null, null)], $result->payload->births);
    }

    public function testSkipsAnEntryWhoseYearIsNotWhole(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->onThisDay()->fixture('on_this_day/selected-03-15.json')
            ->onThisDay()->json('{"births":[{"text":"Half Year, a typo","year":1990.5,"pages":[]},{"text":"Ada Lovelace, mathematician","year":1815,"pages":[]}]}')
            ->onThisDay()->fixture('on_this_day/deaths-03-15.json'));

        // When
        $result = self::fetch(self::DATE);

        // Then
        self::assertInstanceOf(OnThisDayFeed::class, $result->payload);
        self::assertSame(['Ada Lovelace'], array_map(static fn (NotablePerson $person): string => $person->name, $result->payload->births));
    }

    #[DataProvider('failures')]
    public function testFailsTheSourceWhenOneListFails(UpstreamMock $scenario, FailureKind $kind, ?int $status, ?FailureCause $cause): void
    {
        // Given
        self::upstream()->respondWith($scenario);

        // When
        $result = self::fetch(self::DATE);

        // Then
        self::assertSame(SourceStatus::Failed, $result->status);
        self::assertSame($kind, $result->failureKind);
        self::assertSame($status, $result->httpStatus);
        self::assertEquals($cause, $result->cause);
    }

    /**
     * @return iterable<string, array{UpstreamMock, FailureKind, ?int, ?FailureCause}>
     */
    public static function failures(): iterable
    {
        yield 'births unavailable twice' => [
            UpstreamMock::create()
                ->onThisDay()->fixture('on_this_day/selected-03-15.json')
                ->onThisDay()->status(Response::HTTP_SERVICE_UNAVAILABLE)
                ->onThisDay()->fixture('on_this_day/deaths-03-15.json')
                ->onThisDay()->status(Response::HTTP_SERVICE_UNAVAILABLE),
            FailureKind::Unavailable,
            Response::HTTP_SERVICE_UNAVAILABLE,
            null,
        ];
        yield 'deaths not found' => [
            UpstreamMock::create()
                ->onThisDay()->fixture('on_this_day/selected-03-15.json')
                ->onThisDay()->fixture('on_this_day/births-03-15.json')
                ->onThisDay()->status(Response::HTTP_NOT_FOUND),
            FailureKind::Rejected,
            Response::HTTP_NOT_FOUND,
            null,
        ];
        yield 'empty selected list' => [
            UpstreamMock::create()
                ->onThisDay()->json('{"selected":[]}')
                ->onThisDay()->fixture('on_this_day/births-03-15.json')
                ->onThisDay()->fixture('on_this_day/deaths-03-15.json'),
            FailureKind::InvalidPayload,
            Response::HTTP_OK,
            new FailureCause(self::PAYLOAD_ERROR, ['selected'], PayloadDefect::NoEntries),
        ];
        yield 'year as text' => [
            UpstreamMock::create()
                ->onThisDay()->fixture('on_this_day/selected-03-15.json')
                ->onThisDay()->json('{"births":[{"text":"Somebody","year":"1947","pages":[]}]}')
                ->onThisDay()->fixture('on_this_day/deaths-03-15.json'),
            FailureKind::InvalidPayload,
            Response::HTTP_OK,
            new FailureCause(self::PAYLOAD_ERROR, ['births[0].year'], PayloadDefect::WrongShape),
        ];
        yield 'only entries without text' => [
            UpstreamMock::create()
                ->onThisDay()->fixture('on_this_day/selected-03-15.json')
                ->onThisDay()->json('{"births":[{"text":" ","year":1947,"pages":[]}]}')
                ->onThisDay()->fixture('on_this_day/deaths-03-15.json'),
            FailureKind::InvalidPayload,
            Response::HTTP_OK,
            new FailureCause(self::PAYLOAD_ERROR, ['births'], PayloadDefect::NoEntries),
        ];
    }

    private static function fetch(string $date): SourceResult
    {
        $source = self::source();

        return $source->client()->request(new \DateTimeImmutable($date), Location::fromCoordinates(52.23, 21.01))->resolve($source->name());
    }

    private static function source(): OnThisDaySource
    {
        return self::testService(OnThisDaySource::class);
    }

    private static function upstream(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }
}
