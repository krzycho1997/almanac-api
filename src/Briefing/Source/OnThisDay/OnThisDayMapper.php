<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\Exception\PayloadDefect;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
use App\Briefing\Source\PlainText;
use App\Briefing\Source\WebAddress;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OnThisDayMapper
{
    private const string SELECTED_FIELD = 'selected';
    private const string BIRTHS_FIELD = 'births';
    private const string DEATHS_FIELD = 'deaths';
    private const int SMALLEST_LIMIT = 1;
    private const string ATTRIBUTION_NAME = 'Wikipedia';
    private const string ATTRIBUTION_URL = 'https://en.wikipedia.org/';
    private const string LICENSE = 'CC BY-SA 4.0';
    private const string LICENSE_URL = 'https://creativecommons.org/licenses/by-sa/4.0/';
    private const int YEARS_PER_CENTURY = 100;
    private const string EMPTY = '';
    private const string COMMA = ',';
    private const string DISAMBIGUATION = '/\s*\([^()]*\)$/u';
    private const string COMPARABLE = 'NFD; [:Nonspacing Mark:] Remove; Lower(); NFC';
    private const string NAME_ENDS = '/^(?:$|[\s,(])/u';
    private const string WIKIDATA_ID = '/^Q[1-9][0-9]*$/D';

    public function __construct(
        #[Autowire('%env(int:ALMANAC_OTD_LIMIT)%')]
        private int $limit,
    ) {
        if ($limit < self::SMALLEST_LIMIT) {
            throw new \InvalidArgumentException(\sprintf('ALMANAC_OTD_LIMIT must be at least %d, got %d.', self::SMALLEST_LIMIT, $limit));
        }
    }

    public function map(OnThisDayApiResponse $selected, OnThisDayApiResponse $births, OnThisDayApiResponse $deaths): OnThisDayFeed
    {
        return new OnThisDayFeed(
            $this->trimmed(self::required(self::events($selected->selected), self::SELECTED_FIELD)),
            $this->spread(self::required(self::people($births->births), self::BIRTHS_FIELD)),
            $this->spread(self::required(self::people($deaths->deaths), self::DEATHS_FIELD)),
            new Attribution(self::ATTRIBUTION_NAME, self::ATTRIBUTION_URL, self::LICENSE, self::LICENSE_URL, true, null),
        );
    }

    /**
     * @param list<OnThisDayEntry> $entries
     *
     * @return list<HistoricalEvent>
     */
    private static function events(array $entries): array
    {
        $events = [];

        foreach ($entries as $entry) {
            $events[] = self::event($entry);
        }

        return array_values(array_filter($events));
    }

    private static function event(OnThisDayEntry $entry): ?HistoricalEvent
    {
        $text = PlainText::tidy($entry->text);
        $year = $entry->wholeYear();

        if (self::EMPTY === $text) {
            return null;
        }

        if (null === $year) {
            return null;
        }

        return new HistoricalEvent($year, $text, self::pages($entry->pages));
    }

    /**
     * @param list<OnThisDayPage> $pages
     *
     * @return list<PageRef>
     */
    private static function pages(array $pages): array
    {
        $refs = [];

        foreach ($pages as $page) {
            $refs[] = self::pageRef($page);
        }

        return array_values(array_filter($refs));
    }

    private static function pageRef(OnThisDayPage $page): ?PageRef
    {
        $title = PlainText::tidy($page->title);

        if (self::EMPTY === $title) {
            return null;
        }

        return self::linkedPage($title, $page);
    }

    private static function linkedPage(string $title, OnThisDayPage $page): ?PageRef
    {
        if (WebAddress::isUsable($page->url())) {
            return new PageRef($title, $page->url(), self::thumbnail($page), self::wikidataId($page));
        }

        return null;
    }

    /**
     * @param list<OnThisDayEntry> $entries
     *
     * @return list<NotablePerson>
     */
    private static function people(array $entries): array
    {
        $people = [];

        foreach ($entries as $entry) {
            $people[] = self::person($entry);
        }

        return array_values(array_filter($people));
    }

    private static function person(OnThisDayEntry $entry): ?NotablePerson
    {
        $text = PlainText::tidy($entry->text);
        $year = $entry->wholeYear();

        if (self::EMPTY === $text) {
            return null;
        }

        if (null === $year) {
            return null;
        }

        return self::personFrom($year, $text, self::pageAbout($text, $entry->pages));
    }

    /**
     * @param list<OnThisDayPage> $pages
     */
    private static function pageAbout(string $text, array $pages): ?OnThisDayPage
    {
        $named = array_values(array_filter($pages, static fn (OnThisDayPage $page): bool => self::namesThePage($text, $page)));
        usort($named, static fn (OnThisDayPage $first, OnThisDayPage $second): int => self::nameLength($second) <=> self::nameLength($first));

        return $named[0] ?? null;
    }

    private static function nameLength(OnThisDayPage $page): int
    {
        return mb_strlen(self::withoutDisambiguation($page->title));
    }

    private static function namesThePage(string $text, OnThisDayPage $page): bool
    {
        $title = self::comparable(self::withoutDisambiguation($page->title));

        if (self::startsWithName(self::comparable($text), $title)) {
            return true;
        }

        return self::startsWithName($title, self::comparable(self::nameIn($text)));
    }

    private static function startsWithName(string $text, string $name): bool
    {
        if (str_starts_with($text, $name)) {
            return 1 === preg_match(self::NAME_ENDS, substr($text, \strlen($name)));
        }

        return false;
    }

    private static function personFrom(int $year, string $text, ?OnThisDayPage $page): NotablePerson
    {
        if ($page instanceof OnThisDayPage) {
            return self::personOn($year, $text, $page);
        }

        return self::unlinkedPerson($year, $text);
    }

    private static function personOn(int $year, string $text, OnThisDayPage $page): NotablePerson
    {
        $linked = self::linkedPage(self::withoutDisambiguation($page->title), $page);

        if ($linked instanceof PageRef) {
            return new NotablePerson($year, $linked->title, self::description($page->description), $linked->url, $linked->thumbnail, $linked->wikidataId);
        }

        return self::unlinkedPerson($year, $text);
    }

    private static function unlinkedPerson(int $year, string $text): NotablePerson
    {
        return new NotablePerson($year, self::nameIn($text), self::descriptionIn($text), null, null);
    }

    private static function nameIn(string $text): string
    {
        return PlainText::tidy(explode(self::COMMA, $text, 2)[0]);
    }

    private static function descriptionIn(string $text): ?string
    {
        return self::description(explode(self::COMMA, $text, 2)[1] ?? null);
    }

    private static function description(?string $description): ?string
    {
        $text = PlainText::tidy((string) $description);

        if (self::EMPTY === $text) {
            return null;
        }

        return $text;
    }

    private static function thumbnail(OnThisDayPage $page): ?string
    {
        if (WebAddress::isUsable($page->thumbnailUrl())) {
            return $page->thumbnailUrl();
        }

        return null;
    }

    private static function wikidataId(OnThisDayPage $page): ?string
    {
        if (1 === preg_match(self::WIKIDATA_ID, (string) $page->wikidataId)) {
            return $page->wikidataId;
        }

        return null;
    }

    private static function withoutDisambiguation(string $title): string
    {
        return PlainText::tidy((string) preg_replace(self::DISAMBIGUATION, self::EMPTY, $title));
    }

    private static function comparable(string $text): string
    {
        return (string) transliterator_transliterate(self::COMPARABLE, $text);
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private static function required(array $items, string $field): array
    {
        if ([] === $items) {
            throw InvalidUpstreamPayloadException::at($field, PayloadDefect::NoEntries, "Wikipedia On This Day has no usable {$field}.");
        }

        return $items;
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private function trimmed(array $items): array
    {
        return \array_slice($items, 0, $this->limit);
    }

    /**
     * @param list<NotablePerson> $people
     *
     * @return list<NotablePerson>
     */
    private function spread(array $people): array
    {
        $picked = $this->trimmed(self::roundRobin(array_map(self::illustratedFirst(...), self::byCentury($people))));
        usort($picked, static fn (NotablePerson $first, NotablePerson $second): int => $second->year <=> $first->year);

        return $picked;
    }

    /**
     * @param list<NotablePerson> $people
     *
     * @return list<list<NotablePerson>>
     */
    private static function byCentury(array $people): array
    {
        $centuries = [];

        foreach ($people as $person) {
            $centuries[(int) floor($person->year / self::YEARS_PER_CENTURY)][] = $person;
        }

        return array_values($centuries);
    }

    /**
     * @param list<NotablePerson> $people
     *
     * @return list<NotablePerson>
     */
    private static function illustratedFirst(array $people): array
    {
        return [
            ...array_filter($people, self::isIllustrated(...)),
            ...array_filter($people, static fn (NotablePerson $person): bool => false === self::isIllustrated($person)),
        ];
    }

    private static function isIllustrated(NotablePerson $person): bool
    {
        if (null === $person->thumbnail) {
            return false;
        }

        return null !== $person->description;
    }

    /**
     * @param list<list<NotablePerson>> $groups
     *
     * @return list<NotablePerson>
     */
    private static function roundRobin(array $groups): array
    {
        $picked = [];
        $rounds = max([0, ...array_map(\count(...), $groups)]);

        for ($round = 0; $round < $rounds; ++$round) {
            foreach ($groups as $group) {
                $picked[] = $group[$round] ?? null;
            }
        }

        return array_values(array_filter($picked));
    }
}
