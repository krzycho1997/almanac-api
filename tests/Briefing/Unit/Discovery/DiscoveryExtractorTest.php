<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Discovery;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Discovery\DiscoveryExtractor;
use App\Briefing\Discovery\DiscoveryRecord;
use App\Briefing\Entity\Discovery;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\MediaType;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
use App\Briefing\Source\OnThisDay\WikipediaPersonIdentity;
use App\Tests\Support\Briefing\FakePayload;
use App\Tests\Support\Briefing\OpenMeteoPayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscoveryExtractorTest extends TestCase
{
    private const string IDES = '2024-03-15';

    public function testGivesThePictureOfADayTheSameIdForever(): void
    {
        // Given
        $picture = new ApodPicture(new \DateTimeImmutable(self::IDES), 'Portrait of NGC 1055', 'A galaxy.', MediaType::Image, null, null, null, self::attribution());

        // When
        $id = new DiscoveryExtractor(new WikipediaPersonIdentity())->apodId($picture);

        // Then
        self::assertSame('c05f9b6a-9262-5859-b45d-743a62e1bc6e', $id->toRfc4122());
    }

    #[DataProvider('events')]
    public function testKeysAnEventByItsYearDayAndText(HistoricalEvent $event, string $expected): void
    {
        // When
        $id = new DiscoveryExtractor(new WikipediaPersonIdentity())->eventId($event, new \DateTimeImmutable(self::IDES));

        // Then
        self::assertSame($expected, $id->toRfc4122());
    }

    /**
     * @return iterable<string, array{HistoricalEvent, string}>
     */
    public static function events(): iterable
    {
        yield 'before the common era' => [new HistoricalEvent(-44, 'Julius Caesar is assassinated.', []), '50f75411-119e-5a35-865c-52f950c2c53b'];
        yield 'common era' => [new HistoricalEvent(1939, 'Germany occupies Bohemia.', []), '1604e20a-544e-5ab3-97a4-e7861e6ea31a'];
    }

    #[DataProvider('people')]
    public function testKeysAPersonByTheMostStableNameThereIs(NotablePerson $person, string $expected): void
    {
        // When
        $id = new DiscoveryExtractor(new WikipediaPersonIdentity())->personId($person);

        // Then
        self::assertSame($expected, $id->toRfc4122());
    }

    /**
     * @return iterable<string, array{NotablePerson, string}>
     */
    public static function people(): iterable
    {
        yield 'wikidata item' => [new NotablePerson(-100, 'Julius Caesar', null, 'https://en.wikipedia.org/wiki/Julius_Caesar', null, 'Q1048'), '30ad2d96-39f2-514a-806e-58f2041d1ce6'];
        yield 'page title with its disambiguation' => [new NotablePerson(1950, 'John Smith', null, 'https://en.wikipedia.org/wiki/John_Smith_(footballer)', null), 'fa271102-bc32-56f2-b685-1b0b4ce8dcc5'];
        yield 'namesake on another page' => [new NotablePerson(1960, 'John Smith', null, 'https://en.wikipedia.org/wiki/John_Smith_(politician)', null), '191c28af-de11-5428-affa-00c572dccfd7'];
        yield 'no page' => [new NotablePerson(1970, 'Jan Kowalski', null, null, null), '6c6ef5d2-9e6c-51e5-9787-da2bbd22e4f8'];
    }

    public function testKeepsAPersonsIdWhenTheirPageMoves(): void
    {
        // Given
        $extractor = new DiscoveryExtractor(new WikipediaPersonIdentity());

        // When
        $before = $extractor->personId(new NotablePerson(-100, 'Julius Caesar', null, 'https://en.wikipedia.org/wiki/Julius_Caesar', null, 'Q1048'));
        $after = $extractor->personId(new NotablePerson(-100, 'Gaius Julius Caesar', null, 'https://en.wikipedia.org/wiki/Gaius_Julius_Caesar', null, 'Q1048'));

        // Then
        self::assertTrue($before->equals($after));
    }

    public function testTreatsComposedAndDecomposedTextAsTheSameEvent(): void
    {
        // Given
        $extractor = new DiscoveryExtractor(new WikipediaPersonIdentity());
        $day = new \DateTimeImmutable(self::IDES);

        // When
        $composed = $extractor->eventId(new HistoricalEvent(1990, " Pr\u{00E9}sident ", []), $day);
        $decomposed = $extractor->eventId(new HistoricalEvent(1990, "Pre\u{0301}sident", []), $day);

        // Then
        self::assertTrue($composed->equals($decomposed));
    }

    public function testTreatsAnEncodedAndAPlainPageTitleAsTheSamePerson(): void
    {
        // Given
        $extractor = new DiscoveryExtractor(new WikipediaPersonIdentity());

        // When
        $encoded = $extractor->personId(new NotablePerson(1906, 'Kurt Gödel', null, 'https://en.wikipedia.org/wiki/Kurt_G%C3%B6del', null));
        $plain = $extractor->personId(new NotablePerson(1906, 'Kurt Gödel', null, 'https://en.wikipedia.org/wiki/Kurt Gödel', null));

        // Then
        self::assertTrue($encoded->equals($plain));
    }

    #[DataProvider('samePages')]
    public function testReadsTheSamePageTitleFromEveryFormOfItsAddress(string $address, string $sameAs): void
    {
        // Given
        $extractor = new DiscoveryExtractor(new WikipediaPersonIdentity());

        // When
        $first = $extractor->personId(new NotablePerson(1906, 'Someone', null, $address, null));
        $second = $extractor->personId(new NotablePerson(1906, 'Someone', null, $sameAs, null));

        // Then
        self::assertTrue($first->equals($second));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function samePages(): iterable
    {
        yield 'decomposed letter' => ['https://en.wikipedia.org/wiki/Kurt_Go%CC%88del', 'https://en.wikipedia.org/wiki/Kurt_G%C3%B6del'];
        yield 'mobile site' => ['https://en.m.wikipedia.org/wiki/Kurt_G%C3%B6del', 'https://en.wikipedia.org/wiki/Kurt_G%C3%B6del'];
        yield 'plus sign written out or encoded' => ['https://en.wikipedia.org/wiki/A+B', 'https://en.wikipedia.org/wiki/A%2BB'];
        yield 'old revision' => ['https://en.wikipedia.org/wiki/Kurt_G%C3%B6del?oldid=1', 'https://en.wikipedia.org/wiki/Kurt_G%C3%B6del'];
    }

    #[DataProvider('differentPages')]
    public function testTellsDifferentPageTitlesApart(string $address, string $other): void
    {
        // Given
        $extractor = new DiscoveryExtractor(new WikipediaPersonIdentity());

        // When
        $first = $extractor->personId(new NotablePerson(1906, 'Someone', null, $address, null));
        $second = $extractor->personId(new NotablePerson(1906, 'Someone', null, $other, null));

        // Then
        self::assertFalse($first->equals($second));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function differentPages(): iterable
    {
        yield 'plus sign kept' => ['https://en.wikipedia.org/wiki/C%2B%2B_(person)', 'https://en.wikipedia.org/wiki/C_(person)'];
        yield 'compatibility character kept' => ['https://en.wikipedia.org/wiki/%EF%AC%81sher', 'https://en.wikipedia.org/wiki/fisher'];
        yield 'case kept' => ['https://en.wikipedia.org/wiki/Foo', 'https://en.wikipedia.org/wiki/foo'];
    }

    public function testKeysAPersonWhoseWikidataItemIsTooLongByTheirPage(): void
    {
        // When
        $id = new DiscoveryExtractor(new WikipediaPersonIdentity())->personId(new NotablePerson(1950, 'John Smith', null, 'https://en.wikipedia.org/wiki/John_Smith_(footballer)', null, 'Q'.str_repeat('1', Discovery::LONGEST_KEY)));

        // Then
        self::assertSame('fa271102-bc32-56f2-b685-1b0b4ce8dcc5', $id->toRfc4122());
    }

    #[DataProvider('addressesWithoutATitle')]
    public function testKeysAPersonWithoutAReadablePageTitleAsUnlinked(string $address): void
    {
        // When
        $id = new DiscoveryExtractor(new WikipediaPersonIdentity())->personId(new NotablePerson(1970, 'Jan Kowalski', null, $address, null));

        // Then
        self::assertSame('6c6ef5d2-9e6c-51e5-9787-da2bbd22e4f8', $id->toRfc4122());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesWithoutATitle(): iterable
    {
        yield 'empty title' => ['https://en.wikipedia.org/wiki/'];
        yield 'title of only an underscore' => ['https://en.wikipedia.org/wiki/_'];
        yield 'invalid encoding' => ['https://en.wikipedia.org/wiki/%FF'];
        yield 'section of a list' => ['https://en.wikipedia.org/wiki/List_of_Poles#Jan_Kowalski'];
        yield 'not an article path' => ['https://en.wikipedia.org/w/index.php?title=Jan_Kowalski'];
        yield 'article path further in' => ['https://en.wikipedia.org/w/wiki/Jan_Kowalski'];
        yield 'control character in the title' => ['https://en.wikipedia.org/wiki/Jan%00Kowalski'];
        yield 'title too long for the key' => ['https://en.wikipedia.org/wiki/'.str_repeat('J', Discovery::LONGEST_KEY)];
    }

    public function testTreatsComposedAndDecomposedNamesOfAnUnlinkedPersonAlike(): void
    {
        // Given
        $extractor = new DiscoveryExtractor(new WikipediaPersonIdentity());

        // When
        $composed = $extractor->personId(new NotablePerson(1906, " G\u{00F6}del ", null, null, null));
        $decomposed = $extractor->personId(new NotablePerson(1906, "Go\u{0308}del", null, null, null));

        // Then
        self::assertTrue($composed->equals($decomposed));
    }

    public function testDescribesEveryItemOfADayInHistory(): void
    {
        // Given
        $feed = new OnThisDayFeed(
            [new HistoricalEvent(1939, 'Germany occupies Bohemia.', [new PageRef('Bohemia', 'https://en.wikipedia.org/wiki/Bohemia', null)])],
            [new NotablePerson(1950, 'John Smith', 'Footballer', 'https://en.wikipedia.org/wiki/John_Smith_(footballer)', null)],
            [new NotablePerson(-44, 'Julius Caesar', 'Roman dictator', 'https://en.wikipedia.org/wiki/Julius_Caesar', null, 'Q1048')],
            self::attribution(),
        );

        // When
        $records = new DiscoveryExtractor(new WikipediaPersonIdentity())->extract($feed, new \DateTimeImmutable(self::IDES));

        // Then
        self::assertEquals([
            new DiscoveryRecord(DiscoveryId::fromString('1604e20a-544e-5ab3-97a4-e7861e6ea31a'), 'event:1939-03-15:55ad9960237be57d', DiscoveryType::Event, new \DateTimeImmutable('1939-03-15'), 'Germany occupies Bohemia.', null, 'https://en.wikipedia.org/wiki/Bohemia'),
            new DiscoveryRecord(DiscoveryId::fromString('fa271102-bc32-56f2-b685-1b0b4ce8dcc5'), 'person:page:John Smith (footballer)', DiscoveryType::Person, null, 'John Smith', 'Footballer', 'https://en.wikipedia.org/wiki/John_Smith_(footballer)'),
            new DiscoveryRecord(DiscoveryId::fromString('30ad2d96-39f2-514a-806e-58f2041d1ce6'), 'person:Q1048', DiscoveryType::Person, null, 'Julius Caesar', 'Roman dictator', 'https://en.wikipedia.org/wiki/Julius_Caesar'),
        ], $records);
    }

    public function testDescribesThePictureOfADay(): void
    {
        // Given
        $picture = new ApodPicture(new \DateTimeImmutable(self::IDES), 'Portrait of NGC 1055', 'A galaxy.', MediaType::Image, 'https://apod.nasa.gov/a.jpg', null, null, self::attribution());

        // When
        $records = new DiscoveryExtractor(new WikipediaPersonIdentity())->extract($picture, new \DateTimeImmutable(self::IDES));

        // Then
        self::assertEquals([new DiscoveryRecord(DiscoveryId::fromString('c05f9b6a-9262-5859-b45d-743a62e1bc6e'), 'apod:2024-03-15', DiscoveryType::Apod, new \DateTimeImmutable(self::IDES), 'Portrait of NGC 1055', 'A galaxy.', 'https://apod.nasa.gov/a.jpg')], $records);
    }

    #[DataProvider('eventsWithoutADate')]
    public function testLeavesTheDateOutWhenTheCalendarCannotHoldIt(int $year, string $day): void
    {
        // Given
        $feed = new OnThisDayFeed([new HistoricalEvent($year, 'Something happens.', [])], [], [], self::attribution());

        // When
        $records = new DiscoveryExtractor(new WikipediaPersonIdentity())->extract($feed, new \DateTimeImmutable($day));

        // Then
        self::assertNull($records[0]->date);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function eventsWithoutADate(): iterable
    {
        yield 'before the common era' => [-44, self::IDES];
        yield 'year zero' => [0, self::IDES];
        yield 'leap day in a common year' => [1900, '2024-02-29'];
    }

    public function testKeepsTheLeapDayOfALeapYear(): void
    {
        // Given
        $feed = new OnThisDayFeed([new HistoricalEvent(1904, 'Something happens.', [])], [], [], self::attribution());

        // When
        $records = new DiscoveryExtractor(new WikipediaPersonIdentity())->extract($feed, new \DateTimeImmutable('2024-02-29'));

        // Then
        self::assertSame('1904-02-29', $records[0]->date?->format('Y-m-d'));
    }

    public function testFindsNothingToDiscoverInTheWeather(): void
    {
        // When
        $records = new DiscoveryExtractor(new WikipediaPersonIdentity())->extract(OpenMeteoPayloads::dailyWeather(), new \DateTimeImmutable(self::IDES));

        // Then
        self::assertSame([], $records);
    }

    public function testRefusesAPayloadWithoutKeys(): void
    {
        // Then / When
        $this->expectException(\LogicException::class);
        new DiscoveryExtractor(new WikipediaPersonIdentity())->extract(new FakePayload([]), new \DateTimeImmutable(self::IDES));
    }

    private static function attribution(): Attribution
    {
        return new Attribution('Upstream', 'https://upstream.test/', null, null, false, null);
    }
}
