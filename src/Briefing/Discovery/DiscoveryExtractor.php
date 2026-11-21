<?php

declare(strict_types=1);

namespace App\Briefing\Discovery;

use App\Briefing\BriefingDay;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Entity\Discovery;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\SourcePayload;
use Symfony\Component\Uid\Uuid;

final readonly class DiscoveryExtractor
{
    private const string CONTROL_CHARACTER = '/\p{Cc}/u';
    private const string NAMESPACE = '179fdc61-e6b5-43fa-aa77-b824790a27fe';
    private const string APOD_KEY = 'apod:%s';
    private const string EVENT_KEY = 'event:%d-%s:%s';
    private const string PERSON_KEY = 'person:%s';
    private const string PERSON_PAGE_KEY = 'person:page:%s';
    private const string UNLINKED_PERSON_KEY = 'person:#%d:%s';
    private const string MONTH_DAY = 'm-d';
    private const string HASH = 'sha1';
    private const int HASH_LENGTH = 16;
    private const string WIKI_PATH = '/wiki/';
    private const string UNDERSCORE = '_';
    private const string SPACE = ' ';
    private const string FRAGMENT = '#';
    private const int FIRST_YEAR = 1;
    private const string NO_KEYS = 'No discovery keys are defined for %s.';

    /**
     * @return list<DiscoveryRecord>
     */
    public function extract(SourcePayload $payload, \DateTimeImmutable $day): array
    {
        return match (true) {
            $payload instanceof ApodPicture => [self::apod($payload)],
            $payload instanceof OnThisDayFeed => self::feed($payload, $day),
            $payload instanceof DailyWeather => [],
            default => throw new \LogicException(\sprintf(self::NO_KEYS, $payload::class)),
        };
    }

    public function apodId(ApodPicture $picture): DiscoveryId
    {
        return self::idOf(self::apodKey($picture));
    }

    public function eventId(HistoricalEvent $event, \DateTimeImmutable $day): DiscoveryId
    {
        return self::idOf(self::eventKey($event, $day));
    }

    public function personId(NotablePerson $person): DiscoveryId
    {
        return self::idOf(self::personKey($person));
    }

    private static function apod(ApodPicture $picture): DiscoveryRecord
    {
        $key = self::apodKey($picture);

        return new DiscoveryRecord(self::idOf($key), $key, DiscoveryType::Apod, $picture->date, $picture->title, $picture->explanation, $picture->url);
    }

    /**
     * @return list<DiscoveryRecord>
     */
    private static function feed(OnThisDayFeed $feed, \DateTimeImmutable $day): array
    {
        return [
            ...array_map(static fn (HistoricalEvent $event): DiscoveryRecord => self::event($event, $day), $feed->events),
            ...array_map(self::person(...), [...$feed->births, ...$feed->deaths]),
        ];
    }

    private static function event(HistoricalEvent $event, \DateTimeImmutable $day): DiscoveryRecord
    {
        $key = self::eventKey($event, $day);

        return new DiscoveryRecord(self::idOf($key), $key, DiscoveryType::Event, self::eventDate($event, $day), $event->text, null, array_first($event->pages)?->url);
    }

    private static function person(NotablePerson $person): DiscoveryRecord
    {
        $key = self::personKey($person);

        return new DiscoveryRecord(self::idOf($key), $key, DiscoveryType::Person, null, $person->name, $person->description, $person->url);
    }

    private static function eventDate(HistoricalEvent $event, \DateTimeImmutable $day): ?\DateTimeImmutable
    {
        if ($event->year < self::FIRST_YEAR) {
            return null;
        }

        $date = $day->setDate($event->year, (int) $day->format('n'), (int) $day->format('j'));

        if ($date->format(self::MONTH_DAY) === $day->format(self::MONTH_DAY)) {
            return $date;
        }

        return null;
    }

    private static function apodKey(ApodPicture $picture): string
    {
        return \sprintf(self::APOD_KEY, $picture->date->format(BriefingDay::FORMAT));
    }

    private static function eventKey(HistoricalEvent $event, \DateTimeImmutable $day): string
    {
        return \sprintf(self::EVENT_KEY, $event->year, $day->format(self::MONTH_DAY), self::hashOf($event->text));
    }

    private static function personKey(NotablePerson $person): string
    {
        if (null === $person->wikidataId) {
            return self::personKeyWithoutWikidata($person);
        }

        $key = \sprintf(self::PERSON_KEY, $person->wikidataId);

        if (self::fits($key)) {
            return $key;
        }

        return self::personKeyWithoutWikidata($person);
    }

    private static function personKeyWithoutWikidata(NotablePerson $person): string
    {
        $title = self::pageTitleOf($person->url);

        if (null === $title) {
            return \sprintf(self::UNLINKED_PERSON_KEY, $person->year, self::hashOf($person->name));
        }

        return \sprintf(self::PERSON_PAGE_KEY, $title);
    }

    private static function pageTitleOf(?string $url): ?string
    {
        if (str_contains((string) $url, self::FRAGMENT)) {
            return null;
        }

        $path = (string) parse_url((string) $url, \PHP_URL_PATH);

        if (str_starts_with($path, self::WIKI_PATH)) {
            return self::titleIn(rawurldecode(substr($path, \strlen(self::WIKI_PATH))));
        }

        return null;
    }

    private static function titleIn(string $decoded): ?string
    {
        $title = \Normalizer::normalize(trim(str_replace(self::UNDERSCORE, self::SPACE, $decoded)), \Normalizer::FORM_C);

        if (\is_string($title)) {
            return self::usable($title);
        }

        return null;
    }

    private static function usable(string $title): ?string
    {
        if ('' === $title) {
            return null;
        }

        if (1 === preg_match(self::CONTROL_CHARACTER, $title)) {
            return null;
        }

        return self::fitting($title);
    }

    private static function fitting(string $title): ?string
    {
        if (self::fits(\sprintf(self::PERSON_PAGE_KEY, $title))) {
            return $title;
        }

        return null;
    }

    private static function fits(string $key): bool
    {
        return mb_strlen($key) <= Discovery::LONGEST_KEY;
    }

    private static function hashOf(string $text): string
    {
        return substr(hash(self::HASH, self::canonical($text)), 0, self::HASH_LENGTH);
    }

    private static function canonical(string $text): string
    {
        return (string) \Normalizer::normalize(trim($text), \Normalizer::FORM_C);
    }

    private static function idOf(string $key): DiscoveryId
    {
        return new DiscoveryId(Uuid::v5(Uuid::fromString(self::NAMESPACE), $key)->toRfc4122());
    }
}
