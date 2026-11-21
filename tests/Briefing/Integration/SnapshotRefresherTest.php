<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\Entity\Discovery;
use App\Briefing\FailureKind;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
use App\Briefing\SnapshotRefresher;
use App\Briefing\SnapshotScope;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Tests\Support\Briefing\SourcePayloads;
use App\Tests\Support\InteractsWithTestServices;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SnapshotRefresherTest extends KernelTestCase
{
    use InteractsWithTestServices;

    private const string DAY = '2024-03-15';
    private const string TEN = '2026-11-20 10:00:00+00:00';

    public function testStoresDiscoveriesOnlyForResultsWithData(): void
    {
        // Given
        $results = [SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), SourceResult::failed(SourceName::Weather, FailureKind::Unavailable)];

        // When
        $stored = self::refresher()->saveAndReread($results, [SourceName::Apod->value => self::apod(), SourceName::Weather->value => self::weather()], new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::TEN));

        // Then
        self::assertEqualsCanonicalizing([SourceName::Apod->value, SourceName::Weather->value], array_keys($stored));
        self::assertSame(['apod:2026-11-05'], array_map(static fn (Discovery $discovery): string => $discovery->key, self::discoveries()));
    }

    public function testStoresAnEventBeforeTheCommonEraWithoutADate(): void
    {
        // Given
        $feed = new OnThisDayFeed([new HistoricalEvent(-44, 'Julius Caesar is assassinated.', [])], [], [], SourcePayloads::onThisDayFeed()->attribution);

        // When
        self::refresher()->saveAndReread([SourceResult::ok(SourceName::OnThisDay, $feed)], [SourceName::OnThisDay->value => new SnapshotScope(SourceName::OnThisDay, '03-15', null)], new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::TEN));

        // Then
        $discovery = self::discoveries()[0];
        self::assertSame(['event:-44-03-15:88f7837eb45c12b8', null], [$discovery->key, $discovery->date]);
    }

    public function testSavesNothingWhenOneOfTheResultsCannotBeSaved(): void
    {
        // Given
        $results = [SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), SourceResult::failed(SourceName::Weather, FailureKind::Rejected)];

        // When
        try {
            self::refresher()->saveAndReread($results, [SourceName::Apod->value => self::apod()], new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::TEN));
            self::fail('A result without its scope must not be saved.');
        } catch (\InvalidArgumentException) {
        }

        // Then
        self::assertSame([], self::refresher()->storedFor([self::apod()]));
        self::assertSame([], self::discoveries());
    }

    public function testStoresTheDiscoveriesOfEverySourceWithData(): void
    {
        // Given
        $feed = new OnThisDayFeed([new HistoricalEvent(1, 'Something happens.', [new PageRef('Rome', 'https://en.wikipedia.org/wiki/Rome', null)])], [], [], SourcePayloads::onThisDayFeed()->attribution);
        $results = [SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), SourceResult::ok(SourceName::OnThisDay, $feed)];

        // When
        self::refresher()->saveAndReread($results, [SourceName::Apod->value => self::apod(), SourceName::OnThisDay->value => self::onThisDay()], new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::TEN));

        // Then
        $discoveries = self::discoveries();
        self::assertSame(['apod:2026-11-05', 'event:1-03-15:'.substr(sha1('Something happens.'), 0, 16)], array_map(static fn (Discovery $discovery): string => $discovery->key, $discoveries));
        self::assertEquals(['0001-03-15', ['summary' => null, 'url' => 'https://en.wikipedia.org/wiki/Rome']], [$discoveries[1]->date?->format('Y-m-d'), $discoveries[1]->data]);
    }

    public function testKeepsNoSnapshotWhenItsDiscoveriesCannotBeStored(): void
    {
        // Given
        $feed = new OnThisDayFeed([], [new NotablePerson(1900, '', null, null, null)], [], SourcePayloads::onThisDayFeed()->attribution);

        // When
        try {
            self::refresher()->saveAndReread([SourceResult::ok(SourceName::OnThisDay, $feed)], [SourceName::OnThisDay->value => self::onThisDay()], new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::TEN));
            self::fail('A discovery that breaks the table must not be stored.');
        } catch (DriverException) {
        }

        // Then
        self::assertSame([], self::refresher()->storedFor([self::onThisDay()]));
    }

    public function testStoresAPersonWhosePageTitleFillsTheWholeKey(): void
    {
        // Given
        $title = str_repeat('Ż', Discovery::LONGEST_KEY - mb_strlen('person:page:'));
        $feed = new OnThisDayFeed([], [new NotablePerson(1900, 'Someone', null, 'https://en.wikipedia.org/wiki/'.rawurlencode($title), null)], [], SourcePayloads::onThisDayFeed()->attribution);

        // When
        self::refresher()->saveAndReread([SourceResult::ok(SourceName::OnThisDay, $feed)], [SourceName::OnThisDay->value => self::onThisDay()], new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::TEN));

        // Then
        self::assertSame('person:page:'.$title, self::discoveries()[0]->key);
    }

    public function testReadsAgainOnlyTheScopesItJustSaved(): void
    {
        // Given
        $scopes = [SourceName::Apod->value => self::apod(), SourceName::Weather->value => self::weather()];
        self::refresher()->saveAndReread([SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture())], $scopes, new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::TEN));

        // When
        $stored = self::refresher()->saveAndReread([SourceResult::failed(SourceName::Weather, FailureKind::Unavailable)], $scopes, new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::TEN));

        // Then
        self::assertSame([SourceName::Weather->value], array_keys($stored));
    }

    private static function refresher(): SnapshotRefresher
    {
        return self::testService(SnapshotRefresher::class);
    }

    /**
     * @return list<Discovery>
     */
    private static function discoveries(): array
    {
        $entityManager = self::testService(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Discovery::class)->findBy([], ['key' => 'ASC']);
    }

    private static function apod(): SnapshotScope
    {
        return new SnapshotScope(SourceName::Apod, self::DAY, new \DateTimeImmutable(self::DAY));
    }

    private static function onThisDay(): SnapshotScope
    {
        return new SnapshotScope(SourceName::OnThisDay, '03-15', null);
    }

    private static function weather(): SnapshotScope
    {
        return new SnapshotScope(SourceName::Weather, self::DAY.'@52.23,21.01', new \DateTimeImmutable(self::DAY));
    }
}
