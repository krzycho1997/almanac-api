<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Integration;

use App\Bookmark\BookmarkEntry;
use App\Bookmark\BookmarkId;
use App\Bookmark\BookmarkPage;
use App\Bookmark\BookmarkService;
use App\Bookmark\Entity\Bookmark;
use App\Bookmark\Exception\AlreadyBookmarkedException;
use App\Bookmark\Exception\BookmarkNotFoundException;
use App\Bookmark\Exception\DiscoveryNotFoundException;
use App\Bookmark\PageRequest;
use App\Bookmark\Repository\BookmarkRepository;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Contract\DiscoveryView;
use App\Identity\Contract\UserId;
use App\Tests\Support\Bookmark\InMemoryDiscoveryCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class BookmarkServiceTest extends KernelTestCase
{
    private const string NOW = '2026-11-18 12:00:00+00:00';
    private const string PICTURE = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    private const string CAESAR = '30ad2d96-39f2-514a-806e-58f2041d1ce6';
    private const string EVENT = '1604e20a-544e-5ab3-97a4-e7861e6ea31a';
    private const string UNKNOWN = '2ed6657d-e927-568b-95e1-2665a8aea6a2';

    private InMemoryDiscoveryCatalog $discoveries;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->discoveries = new InMemoryDiscoveryCatalog();
        $this->discoveries->add(self::view(self::PICTURE, 'Portrait of NGC 1055'), self::view(self::CAESAR, 'Julius Caesar'), self::view(self::EVENT, 'Germany occupies Bohemia.'));
        $this->clock = new MockClock(self::NOW);
    }

    public function testBookmarksADiscoveryWithItsView(): void
    {
        // Given
        $owner = new UserId();

        // When
        $entry = $this->service()->add($owner, DiscoveryId::fromString(self::PICTURE));

        // Then
        self::assertSame('Portrait of NGC 1055', $entry->discovery->title);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $entry->bookmark->createdAt);
        self::assertSame([$entry->bookmark->id->toRfc4122()], self::storedIdsOf($owner));
    }

    public function testRefusesToBookmarkTheSameDiscoveryTwice(): void
    {
        // Given
        $owner = new UserId();
        $this->service()->add($owner, DiscoveryId::fromString(self::PICTURE));

        // Then / When
        $this->expectException(AlreadyBookmarkedException::class);
        $this->service()->add($owner, DiscoveryId::fromString(self::PICTURE));
    }

    public function testLetsAnotherUserBookmarkTheSameDiscovery(): void
    {
        // Given
        $this->service()->add(new UserId(), DiscoveryId::fromString(self::PICTURE));
        $other = new UserId();

        // When
        $this->service()->add($other, DiscoveryId::fromString(self::PICTURE));

        // Then
        self::assertCount(1, self::storedIdsOf($other));
    }

    public function testRefusesADiscoveryTheCatalogDoesNotKnowAndStoresNothing(): void
    {
        // Given
        $owner = new UserId();

        // When
        try {
            $this->service()->add($owner, DiscoveryId::fromString(self::UNKNOWN));
            self::fail('An unknown discovery was bookmarked.');
        } catch (DiscoveryNotFoundException) {
        }

        // Then
        self::assertSame([], self::storedIdsOf($owner));
    }

    public function testPagesTheOwnersBookmarksNewestFirstWithTheTotal(): void
    {
        // Given
        $owner = new UserId();
        $first = $this->addAt($owner, self::PICTURE, '-2 minutes');
        $second = $this->addAt($owner, self::CAESAR, '-1 minute');
        $third = $this->addAt($owner, self::EVENT, 'now');
        $this->service()->add(new UserId(), DiscoveryId::fromString(self::PICTURE));

        // When
        $pages = [$this->service()->page($owner, new PageRequest(1, 2)), $this->service()->page($owner, new PageRequest(2, 2)), $this->service()->page($owner, new PageRequest(3, 2))];

        // Then
        self::assertSame([[$third, $second], [$first], []], array_map(self::idsOf(...), $pages));
        self::assertSame([3, 3, 3], array_map(static fn (BookmarkPage $page): int => $page->total, $pages));
        self::assertSame(['Germany occupies Bohemia.', 'Julius Caesar'], array_map(static fn (BookmarkEntry $entry): string => $entry->discovery->title, $pages[0]->entries));
    }

    public function testBreaksTiesWithinOneSecondByCreationOrder(): void
    {
        // Given
        $owner = new UserId();
        $first = $this->service()->add($owner, DiscoveryId::fromString(self::PICTURE))->bookmark->id->toRfc4122();
        $second = $this->service()->add($owner, DiscoveryId::fromString(self::CAESAR))->bookmark->id->toRfc4122();

        // When
        $page = $this->service()->page($owner, new PageRequest());

        // Then
        self::assertSame([$second, $first], self::idsOf($page));
    }

    public function testTreatsABookmarkWhoseDiscoveryTheCatalogNoLongerKnowsAsABrokenInvariant(): void
    {
        // Given
        $owner = new UserId();
        $this->service()->add($owner, DiscoveryId::fromString(self::PICTURE));
        $this->discoveries = new InMemoryDiscoveryCatalog();

        // Then / When
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/points at discovery '.self::PICTURE.', which the catalog does not know/');
        $this->service()->page($owner, new PageRequest());
    }

    public function testRemovesTheOwnersBookmark(): void
    {
        // Given
        $owner = new UserId();
        $id = $this->service()->add($owner, DiscoveryId::fromString(self::PICTURE))->bookmark->id;

        // When
        $this->service()->remove($owner, $id);

        // Then
        self::assertSame([], self::storedIdsOf($owner));
    }

    public function testTreatsSomeoneElsesBookmarkAsMissingAndKeepsIt(): void
    {
        // Given
        $owner = new UserId();
        $id = $this->service()->add($owner, DiscoveryId::fromString(self::PICTURE))->bookmark->id;

        // When
        try {
            $this->service()->remove(new UserId(), $id);
            self::fail('Someone else removed the bookmark.');
        } catch (BookmarkNotFoundException) {
        }

        // Then
        self::assertSame([$id->toRfc4122()], self::storedIdsOf($owner));
    }

    public function testTreatsAnUnknownBookmarkAsMissing(): void
    {
        // Then / When
        $this->expectException(BookmarkNotFoundException::class);
        $this->service()->remove(new UserId(), new BookmarkId());
    }

    private function addAt(UserId $owner, string $discovery, string $when): string
    {
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW)->modify($when));

        return $this->service()->add($owner, DiscoveryId::fromString($discovery))->bookmark->id->toRfc4122();
    }

    private function service(): BookmarkService
    {
        return new BookmarkService(self::repository(), $this->discoveries, $this->clock);
    }

    /**
     * @return list<string>
     */
    private static function idsOf(BookmarkPage $page): array
    {
        return array_map(static fn (BookmarkEntry $entry): string => $entry->bookmark->id->toRfc4122(), $page->entries);
    }

    /**
     * @return list<string>
     */
    private static function storedIdsOf(UserId $owner): array
    {
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        return array_map(static fn (Bookmark $bookmark): string => $bookmark->id->toRfc4122(), self::repository()->findBy(['userId' => $owner]));
    }

    private static function repository(): BookmarkRepository
    {
        return self::getContainer()->get(BookmarkRepository::class);
    }

    private static function view(string $id, string $title): DiscoveryView
    {
        return new DiscoveryView(DiscoveryId::fromString($id), DiscoveryType::Event, null, $title, null, null);
    }
}
