<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Unit;

use App\Bookmark\Entity\Bookmark;
use App\Briefing\Contract\DiscoveryId;
use App\Identity\Contract\UserId;
use PHPUnit\Framework\TestCase;

final class BookmarkTest extends TestCase
{
    private const string DISCOVERY = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    private const string CREATED_AT = '2026-11-18 12:00:00.987654+01:00';
    private const string WHOLE_SECONDS = '2026-11-18T12:00:00+01:00';
    private const int BOOKMARKS = 50;

    public function testCutsTheCreationTimeToWholeSeconds(): void
    {
        // Given
        $userId = new UserId();

        // When
        $bookmark = new Bookmark($userId, DiscoveryId::fromString(self::DISCOVERY), new \DateTimeImmutable(self::CREATED_AT));

        // Then
        self::assertEquals(new \DateTimeImmutable(self::WHOLE_SECONDS), $bookmark->createdAt);
        self::assertTrue($userId->equals($bookmark->userId));
        self::assertSame(self::DISCOVERY, $bookmark->discoveryId->toRfc4122());
    }

    public function testOrdersTheIdsOfBookmarksMadeInTheSameSecondByCreation(): void
    {
        // Given
        $createdAt = new \DateTimeImmutable(self::CREATED_AT);

        // When
        $ids = array_map(static fn (): string => new Bookmark(new UserId(), DiscoveryId::fromString(self::DISCOVERY), $createdAt)->id->toRfc4122(), range(1, self::BOOKMARKS));

        // Then
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids);
        self::assertCount(self::BOOKMARKS, array_unique($ids));
    }
}
