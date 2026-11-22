<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source\OnThisDay;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\Exception\PayloadDefect;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\PageRef;
use App\Briefing\Source\OnThisDay\OnThisDayApiResponse;
use App\Briefing\Source\OnThisDay\OnThisDayContentUrls;
use App\Briefing\Source\OnThisDay\OnThisDayEntry;
use App\Briefing\Source\OnThisDay\OnThisDayLink;
use App\Briefing\Source\OnThisDay\OnThisDayMapper;
use App\Briefing\Source\OnThisDay\OnThisDayPage;
use App\Briefing\Source\OnThisDay\OnThisDayThumbnail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OnThisDayMapperTest extends TestCase
{
    private const int LIMIT = 2;
    private const string WIKI = 'https://en.wikipedia.org/wiki/';
    private const string PORTRAIT = 'https://upload.wikimedia.org/caesar.jpg';

    public function testMapsEventsWithTheirPages(): void
    {
        // Given
        $selected = [new OnThisDayEntry(" Julius Caesar , the dictator, was stabbed.\n", -44, [self::caesar(), self::page('Roman Republic')])];

        // When
        $feed = self::mapper()->map(self::selected($selected), self::births([self::born()]), self::deaths([self::born()]));

        // Then
        self::assertEquals(
            [new HistoricalEvent(-44, 'Julius Caesar, the dictator, was stabbed.', [new PageRef('Julius Caesar', self::WIKI.'Julius_Caesar', self::PORTRAIT, 'Q1048'), new PageRef('Roman Republic', self::WIKI.'Roman_Republic', null)])],
            $feed->events,
        );
    }

    #[DataProvider('wikidataIds')]
    public function testKeepsOnlyAWikidataIdOfTheRightForm(?string $id, ?string $expected): void
    {
        // Given
        $selected = [new OnThisDayEntry('Caesar is stabbed.', -44, [new OnThisDayPage('Julius Caesar', self::links('Julius_Caesar'), null, null, $id)])];

        // When
        $feed = self::mapper()->map(self::selected($selected), self::births([self::born()]), self::deaths([self::born()]));

        // Then
        self::assertSame($expected, $feed->events[0]->pages[0]->catalogueId);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function wikidataIds(): iterable
    {
        yield 'item' => ['Q1048', 'Q1048'];
        yield 'none' => [null, null];
        yield 'empty' => ['', null];
        yield 'prefixed' => ['wd:Q1048', null];
        yield 'control character' => ["Q1048\0", null];
        yield 'line break at the end' => ["Q1048\n", null];
        yield 'leading zero' => ['Q01048', null];
        yield 'property instead of an item' => ['P31', null];
    }

    #[DataProvider('peopleAndTheirPages')]
    public function testLinksAPersonOnlyToThePageAboutThem(OnThisDayEntry $entry, NotablePerson $expected): void
    {
        // When
        $feed = self::mapper()->map(self::selected([self::event()]), self::births([self::born()]), self::deaths([$entry]));

        // Then
        self::assertEquals([$expected], $feed->deaths);
        self::assertSame($expected->description, $feed->deaths[0]->description);
    }

    /**
     * @return iterable<string, array{OnThisDayEntry, NotablePerson}>
     */
    public static function peopleAndTheirPages(): iterable
    {
        yield 'first page' => [
            new OnThisDayEntry('Julius Caesar, Roman general and statesman (born 100 BC)', -44, [self::caesar(), self::page('Roman Republic')]),
            new NotablePerson(-44, 'Julius Caesar', 'Roman general and dictator (100–44 BC)', self::WIKI.'Julius_Caesar', self::PORTRAIT, 'Q1048'),
        ];
        yield 'page of the band, not the musician' => [
            new OnThisDayEntry('Fred White, American musician and songwriter (born 1955)', 2023, [self::page('Earth, Wind & Fire', 'American musical group')]),
            new NotablePerson(2023, 'Fred White', 'American musician and songwriter (born 1955)', null, null),
        ];
        yield 'second page' => [
            new OnThisDayEntry('William Lamb, 2nd Viscount Melbourne, English politician (born 1779)', 1848, [self::page('Prime Minister of the United Kingdom'), self::page('William Lamb, 2nd Viscount Melbourne', 'Prime Minister of the United Kingdom')]),
            new NotablePerson(1848, 'William Lamb, 2nd Viscount Melbourne', 'Prime Minister of the United Kingdom', self::WIKI.'William_Lamb,_2nd_Viscount_Melbourne', null),
        ];
        yield 'longer of two pages the name starts with' => [
            new OnThisDayEntry('John Smith Jr., American actor', 1950, [self::page('John Smith', 'English explorer'), self::page('John Smith Jr.', 'American actor')]),
            new NotablePerson(1950, 'John Smith Jr.', 'American actor', self::WIKI.'John_Smith_Jr.', null),
        ];
        yield 'full title before the name alone' => [
            new OnThisDayEntry('William Lamb, 2nd Viscount Melbourne, English politician (born 1779)', 1848, [self::page('William Lamb', 'English cricketer'), self::page('William Lamb, 2nd Viscount Melbourne', 'Prime Minister of the United Kingdom')]),
            new NotablePerson(1848, 'William Lamb, 2nd Viscount Melbourne', 'Prime Minister of the United Kingdom', self::WIKI.'William_Lamb,_2nd_Viscount_Melbourne', null),
        ];
        yield 'title with disambiguation and accents' => [
            new OnThisDayEntry('Gunter Wendt, German-American engineer', 2010, [new OnThisDayPage('Günter Wendt (engineer)', self::links('G%C3%BCnter_Wendt_(engineer)'), ' ')]),
            new NotablePerson(2010, 'Günter Wendt', null, self::WIKI.'G%C3%BCnter_Wendt_(engineer)', null),
        ];
        yield 'title longer than the name in the text' => [
            new OnThisDayEntry('Anne de Montmorency, French captain and diplomat (died 1567)', 1493, [self::page('Anne de Montmorency, 1st Duke of Montmorency', 'French soldier')]),
            new NotablePerson(1493, 'Anne de Montmorency, 1st Duke of Montmorency', 'French soldier', self::WIKI.'Anne_de_Montmorency,_1st_Duke_of_Montmorency', null),
        ];
        yield 'title in another letter case' => [
            new OnThisDayEntry('Bell Hooks, American author', 2021, [self::page('bell hooks', 'American author')]),
            new NotablePerson(2021, 'bell hooks', 'American author', self::WIKI.'bell_hooks', null),
        ];
        yield 'title that ends inside a word of the name' => [
            new OnThisDayEntry('Johnsonville Lee, singer', 1990, [self::page('Johnson')]),
            new NotablePerson(1990, 'Johnsonville Lee', 'singer', null, null),
        ];
        yield 'title followed by more of the name' => [
            new OnThisDayEntry('Shunzhi Emperor of China (died 1661)', 1638, [self::page('Shunzhi Emperor', 'Emperor of China')]),
            new NotablePerson(1638, 'Shunzhi Emperor', 'Emperor of China', self::WIKI.'Shunzhi_Emperor', null),
        ];
        yield 'matching page without a web address' => [
            new OnThisDayEntry('Nobody, nobody at all', 1901, [new OnThisDayPage('Nobody', new OnThisDayContentUrls(new OnThisDayLink('/wiki/Nobody')))]),
            new NotablePerson(1901, 'Nobody', 'nobody at all', null, null),
        ];
        yield 'portrait with a script address' => [
            new OnThisDayEntry('Ellie Leach, English actress', 2001, [new OnThisDayPage('Ellie Leach', self::links('Ellie_Leach'), 'English actress (born 2001)', new OnThisDayThumbnail('javascript:alert(1)'))]),
            new NotablePerson(2001, 'Ellie Leach', 'English actress (born 2001)', self::WIKI.'Ellie_Leach', null),
        ];
    }

    public function testSpreadsPeopleOverTheCenturiesNewestFirst(): void
    {
        // Given
        $births = [
            self::person('Ana', 2005),
            self::person('Cleo', 2004, portraitOnly: true),
            self::person('Ben', 2003, illustrated: true),
            self::person('Dora', 1955),
            self::person('Ewa', 1903),
            self::person('Finn', 1850),
            self::person('Gaius', -100),
        ];

        // When
        $feed = new OnThisDayMapper(5)->map(self::selected([self::event()]), self::births($births), self::deaths([self::born()]));

        // Then
        self::assertSame([[2005, 'Ana'], [2003, 'Ben'], [1955, 'Dora'], [1850, 'Finn'], [-100, 'Gaius']], array_map(static fn (NotablePerson $person): array => [$person->year, $person->name], $feed->births));
    }

    public function testTrimsEveryListToTheLimit(): void
    {
        // Given
        $entries = [self::event(), self::event(), self::event()];
        $people = [self::born(), self::born(), self::born()];

        // When
        $feed = self::mapper()->map(self::selected($entries), self::births($people), self::deaths($people));

        // Then
        self::assertSame([self::LIMIT, self::LIMIT, self::LIMIT], [\count($feed->events), \count($feed->births), \count($feed->deaths)]);
    }

    public function testSkipsEntriesAndPagesWithoutText(): void
    {
        // Given
        $selected = [new OnThisDayEntry(' ', 1900, [self::caesar()]), new OnThisDayEntry('Caesar is stabbed.', -44, [self::page(' '), self::page('Nowhere', null, null), self::caesar()])];
        $births = [new OnThisDayEntry("\n", 1900), self::born()];

        // When
        $feed = self::mapper()->map(self::selected($selected), self::births($births), self::deaths([self::born()]));

        // Then
        self::assertSame(['Caesar is stabbed.'], array_map(static fn (HistoricalEvent $event): string => $event->text, $feed->events));
        self::assertSame(['Julius Caesar'], array_map(static fn (PageRef $page): string => $page->title, $feed->events[0]->pages));
        self::assertSame(['Wings Hauser'], array_map(static fn (NotablePerson $person): string => $person->name, $feed->births));
    }

    public function testSkipsAPersonWithoutAName(): void
    {
        // Given
        $births = [new OnThisDayEntry(', a painter', 1900), new OnThisDayEntry('(painter), somebody', 1901, [self::page('(painter)', 'Somebody')]), self::born()];

        // When
        $feed = self::mapper()->map(self::selected([self::event()]), self::births($births), self::deaths([self::born()]));

        // Then
        self::assertSame(['Wings Hauser'], array_map(static fn (NotablePerson $person): string => $person->name, $feed->births));
    }

    public function testSkipsAnEntryWhoseYearIsNotWhole(): void
    {
        // Given
        $births = [new OnThisDayEntry('Half Year, a typo', 1990.5), new OnThisDayEntry('Far Future, a broken record', 1e20), self::born()];

        // When
        $feed = self::mapper()->map(self::selected([new OnThisDayEntry('A year with a fraction.', 1066.5), new OnThisDayEntry('Caesar is stabbed.', -44, [self::caesar()])]), self::births($births), self::deaths([self::born()]));

        // Then
        self::assertSame(['Caesar is stabbed.'], array_map(static fn (HistoricalEvent $event): string => $event->text, $feed->events));
        self::assertSame(['Wings Hauser'], array_map(static fn (NotablePerson $person): string => $person->name, $feed->births));
    }

    #[DataProvider('emptyLists')]
    public function testRejectsAListWithoutUsableEntries(OnThisDayApiResponse $selected, OnThisDayApiResponse $births, OnThisDayApiResponse $deaths, string $field): void
    {
        // When
        $rejection = self::rejectionOf($selected, $births, $deaths);

        // Then
        self::assertSame([$field], $rejection->fields());
        self::assertSame(PayloadDefect::NoEntries, $rejection->defect());
    }

    /**
     * @return iterable<string, array{OnThisDayApiResponse, OnThisDayApiResponse, OnThisDayApiResponse, string}>
     */
    public static function emptyLists(): iterable
    {
        yield 'no events' => [self::selected([]), self::births([self::born()]), self::deaths([self::born()]), 'selected'];
        yield 'no births' => [self::selected([self::event()]), self::births([]), self::deaths([self::born()]), 'births'];
        yield 'only blank deaths' => [self::selected([self::event()]), self::births([self::born()]), self::deaths([new OnThisDayEntry(' ', 1900)]), 'deaths'];
        yield 'list under another key' => [self::births([self::born()]), self::births([self::born()]), self::deaths([self::born()]), 'selected'];
    }

    public function testRefusesALimitBelowOne(): void
    {
        // Then / When
        $this->expectException(\InvalidArgumentException::class);
        new OnThisDayMapper(0);
    }

    private static function mapper(): OnThisDayMapper
    {
        return new OnThisDayMapper(self::LIMIT);
    }

    private static function rejectionOf(OnThisDayApiResponse $selected, OnThisDayApiResponse $births, OnThisDayApiResponse $deaths): InvalidUpstreamPayloadException
    {
        try {
            self::mapper()->map($selected, $births, $deaths);
        } catch (InvalidUpstreamPayloadException $exception) {
            return $exception;
        }

        self::fail('The lists must be rejected.');
    }

    /**
     * @param list<OnThisDayEntry> $entries
     */
    private static function selected(array $entries): OnThisDayApiResponse
    {
        return new OnThisDayApiResponse(selected: $entries);
    }

    /**
     * @param list<OnThisDayEntry> $entries
     */
    private static function births(array $entries): OnThisDayApiResponse
    {
        return new OnThisDayApiResponse(births: $entries);
    }

    /**
     * @param list<OnThisDayEntry> $entries
     */
    private static function deaths(array $entries): OnThisDayApiResponse
    {
        return new OnThisDayApiResponse(deaths: $entries);
    }

    private static function event(): OnThisDayEntry
    {
        return new OnThisDayEntry('Caesar is stabbed.', -44, [self::caesar()]);
    }

    private static function born(): OnThisDayEntry
    {
        return self::person('Wings Hauser', 1947);
    }

    private static function person(string $name, int $year, bool $illustrated = false, bool $portraitOnly = false): OnThisDayEntry
    {
        $portrait = $illustrated || $portraitOnly ? new OnThisDayThumbnail(self::PORTRAIT) : null;
        $description = $portraitOnly ? null : 'Somebody';

        return new OnThisDayEntry("{$name}, somebody", $year, [new OnThisDayPage($name, self::links($name), $description, $portrait)]);
    }

    private static function caesar(): OnThisDayPage
    {
        return new OnThisDayPage('Julius Caesar', self::links('Julius_Caesar'), 'Roman general and dictator (100–44 BC)', new OnThisDayThumbnail(self::PORTRAIT), 'Q1048');
    }

    private static function page(string $title, ?string $description = null, ?string $path = ''): OnThisDayPage
    {
        $links = null === $path ? null : self::links(str_replace(' ', '_', $title));

        return new OnThisDayPage($title, $links, $description);
    }

    private static function links(string $path): OnThisDayContentUrls
    {
        return new OnThisDayContentUrls(new OnThisDayLink(self::WIKI.$path));
    }
}
