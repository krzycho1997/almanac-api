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
    private const string NAMESPACE = '179fdc61-e6b5-43fa-aa77-b824790a27fe';
    private const string APOD_KEY = 'apod:%s';
    private const string EVENT_KEY = 'event:%d-%s:%s';
    private const string MONTH_DAY = 'm-d';
    private const string HASH = 'sha1';
    private const int HASH_LENGTH = 16;
    private const int FIRST_YEAR = 1;
    private const string NO_KEYS = 'No discovery keys are defined for %s.';

    public function __construct(
        private PersonIdentity $identity,
    ) {
    }

    /**
     * @return list<DiscoveryRecord>
     */
    public function extract(SourcePayload $payload, \DateTimeImmutable $day): array
    {
        return match (true) {
            $payload instanceof ApodPicture => [self::apod($payload)],
            $payload instanceof OnThisDayFeed => $this->feed($payload, $day),
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
        return self::idOf($this->personKey($person));
    }

    private static function apod(ApodPicture $picture): DiscoveryRecord
    {
        $key = self::apodKey($picture);

        return new DiscoveryRecord(self::idOf($key), $key, DiscoveryType::Apod, $picture->date, $picture->title, $picture->explanation, $picture->url);
    }

    /**
     * @return list<DiscoveryRecord>
     */
    private function feed(OnThisDayFeed $feed, \DateTimeImmutable $day): array
    {
        return [
            ...array_map(static fn (HistoricalEvent $event): DiscoveryRecord => self::event($event, $day), $feed->events),
            ...array_map($this->person(...), [...$feed->births, ...$feed->deaths]),
        ];
    }

    private static function event(HistoricalEvent $event, \DateTimeImmutable $day): DiscoveryRecord
    {
        $key = self::eventKey($event, $day);

        return new DiscoveryRecord(self::idOf($key), $key, DiscoveryType::Event, self::eventDate($event, $day), $event->text, null, array_first($event->pages)?->url);
    }

    private function person(NotablePerson $person): DiscoveryRecord
    {
        $key = $this->personKey($person);

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

    private function personKey(NotablePerson $person): string
    {
        foreach ($this->identity->referencesOf($person) as $reference) {
            if (self::fits($reference->key)) {
                return $reference->key;
            }
        }

        return PersonReference::unlinked($person->year, self::hashOf($person->name))->key;
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
