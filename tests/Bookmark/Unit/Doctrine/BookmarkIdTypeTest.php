<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Unit\Doctrine;

use App\Bookmark\BookmarkId;
use App\Bookmark\Doctrine\BookmarkIdType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\UuidV4;
use Symfony\Component\Uid\UuidV7;

final class BookmarkIdTypeTest extends TestCase
{
    public function testReadsAStoredIdAsABookmarkId(): void
    {
        // Given
        $id = new BookmarkId();

        // When
        $read = new BookmarkIdType()->convertToPHPValue($id->toRfc4122(), new PostgreSQLPlatform());

        // Then
        self::assertInstanceOf(BookmarkId::class, $read);
        self::assertTrue($id->equals($read));
    }

    public function testReadsAnotherVersion7ObjectAsABookmarkId(): void
    {
        // Given
        $id = new UuidV7();

        // When
        $read = new BookmarkIdType()->convertToPHPValue($id, new PostgreSQLPlatform());

        // Then
        self::assertInstanceOf(BookmarkId::class, $read);
        self::assertTrue($id->equals($read));
    }

    #[DataProvider('unreadableValues')]
    public function testRefusesToReadAnythingButAVersion7Id(mixed $value): void
    {
        // Then / When
        $this->expectException(ConversionException::class);
        new BookmarkIdType()->convertToPHPValue($value, new PostgreSQLPlatform());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadableValues(): iterable
    {
        yield 'version 4 string' => [new UuidV4()->toRfc4122()];
        yield 'version 4 object' => [new UuidV4()];
        yield 'garbage' => ['not-a-uuid'];
        yield 'integer' => [42];
    }

    public function testStoresABookmarkIdAsItsCanonicalString(): void
    {
        // Given
        $id = new BookmarkId();

        // When
        $stored = new BookmarkIdType()->convertToDatabaseValue($id, new PostgreSQLPlatform());

        // Then
        self::assertSame($id->toRfc4122(), $stored);
    }

    #[DataProvider('foreignIds')]
    public function testRefusesToStoreAnotherKindOfId(mixed $value): void
    {
        // Then / When
        $this->expectException(ConversionException::class);
        new BookmarkIdType()->convertToDatabaseValue($value, new PostgreSQLPlatform());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function foreignIds(): iterable
    {
        yield 'version 7 id of another kind' => [new UuidV7()];
        yield 'version 4 object' => [new UuidV4()];
        yield 'ulid' => [new Ulid()];
        yield 'version 4 string' => [new UuidV4()->toRfc4122()];
        yield 'integer' => [42];
    }

    public function testKeepsNullBothWays(): void
    {
        // Given
        $type = new BookmarkIdType();
        $platform = new PostgreSQLPlatform();

        // When / Then
        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));
    }
}
