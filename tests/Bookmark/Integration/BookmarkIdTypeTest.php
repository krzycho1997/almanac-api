<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Integration;

use App\Bookmark\BookmarkId;
use App\Bookmark\Doctrine\BookmarkIdType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookmarkIdTypeTest extends KernelTestCase
{
    private const string ROUND_TRIP = 'SELECT CAST(:id AS uuid)';

    public function testStoresABookmarkIdAsAPostgresUuidAndReadsItBackAsABookmarkId(): void
    {
        // Given
        $connection = self::getContainer()->get(Connection::class);
        $id = new BookmarkId();

        // When
        $stored = $connection->fetchOne(self::ROUND_TRIP, ['id' => $id], ['id' => BookmarkIdType::NAME]);
        $read = $connection->convertToPHPValue($stored, BookmarkIdType::NAME);

        // Then
        self::assertSame($id->toRfc4122(), $stored);
        self::assertInstanceOf(BookmarkId::class, $read);
        self::assertTrue($id->equals($read));
    }
}
