<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Unit\Mapper;

use App\Bookmark\BookmarkEntry;
use App\Bookmark\Entity\Bookmark;
use App\Bookmark\Mapper\BookmarkResponseMapper;
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

    private static function entry(DiscoveryView $view): BookmarkEntry
    {
        return new BookmarkEntry(new Bookmark(new UserId(), $view->id, new \DateTimeImmutable(self::CREATED_AT)), $view);
    }
}
