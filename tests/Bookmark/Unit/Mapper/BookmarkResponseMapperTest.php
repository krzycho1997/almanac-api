<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Unit\Mapper;

use App\Bookmark\BookmarkEntry;
use App\Bookmark\BookmarkPage;
use App\Bookmark\Dto\Response\BookmarkResponse;
use App\Bookmark\Entity\Bookmark;
use App\Bookmark\Mapper\BookmarkResponseMapper;
use App\Bookmark\PageRequest;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Contract\DiscoveryView;
use App\Identity\Contract\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookmarkResponseMapperTest extends TestCase
{
    private const string DISCOVERY = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    private const string CREATED_AT = '2026-11-18 12:00:00+00:00';

    public function testMapsTheBookmarkAndItsDiscovery(): void
    {
        // Given
        $entry = self::entry(new DiscoveryView(DiscoveryId::fromString(self::DISCOVERY), DiscoveryType::Apod, new \DateTimeImmutable('2024-03-15 00:00:00+01:00'), 'Portrait of NGC 1055', 'A galaxy.', 'https://apod.nasa.gov/a.jpg'));

        // When
        $response = new BookmarkResponseMapper()->map($entry);

        // Then
        self::assertSame($entry->bookmark->id->toRfc4122(), $response->id);
        self::assertEquals(new \DateTimeImmutable(self::CREATED_AT), $response->createdAt);
        self::assertSame(
            [self::DISCOVERY, 'apod', '2024-03-15', 'Portrait of NGC 1055', 'A galaxy.', 'https://apod.nasa.gov/a.jpg'],
            [$response->discovery->id, $response->discovery->type->value, $response->discovery->date, $response->discovery->title, $response->discovery->summary, $response->discovery->url],
        );
    }

    #[DataProvider('types')]
    public function testNamesEveryDiscoveryType(DiscoveryType $type, string $name): void
    {
        // Given
        $entry = self::entry(new DiscoveryView(DiscoveryId::fromString(self::DISCOVERY), $type, null, 'Title', null, null));

        // When
        $response = new BookmarkResponseMapper()->map($entry);

        // Then
        self::assertSame([$name, null, null, null], [$response->discovery->type->value, $response->discovery->date, $response->discovery->summary, $response->discovery->url]);
    }

    /**
     * @return iterable<string, array{DiscoveryType, string}>
     */
    public static function types(): iterable
    {
        yield 'picture' => [DiscoveryType::Apod, 'apod'];
        yield 'event' => [DiscoveryType::Event, 'event'];
        yield 'person' => [DiscoveryType::Person, 'person'];
    }

    public function testMapsAPageWithItsCounts(): void
    {
        // Given
        $entries = [self::entry(self::view()), self::entry(self::view())];
        $page = new BookmarkPage($entries, 7, new PageRequest(2, 3));

        // When
        $list = new BookmarkResponseMapper()->mapPage($page);

        // Then
        self::assertSame([$entries[0]->bookmark->id->toRfc4122(), $entries[1]->bookmark->id->toRfc4122()], array_map(static fn (BookmarkResponse $bookmark): string => $bookmark->id, $list->data));
        self::assertSame([2, 3, 7, 3], [$list->meta->page, $list->meta->limit, $list->meta->total, $list->meta->totalPages]);
    }

    private static function view(): DiscoveryView
    {
        return new DiscoveryView(DiscoveryId::fromString(self::DISCOVERY), DiscoveryType::Event, null, 'Title', null, null);
    }

    private static function entry(DiscoveryView $view): BookmarkEntry
    {
        return new BookmarkEntry(new Bookmark(new UserId(), $view->id, new \DateTimeImmutable(self::CREATED_AT)), $view);
    }
}
