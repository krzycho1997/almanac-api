<?php

declare(strict_types=1);

namespace App\Tests\Identity\Unit\Contract;

use App\Identity\Contract\UserId;
use App\Identity\Contract\UserIdType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\UuidV4;
use Symfony\Component\Uid\UuidV7;

final class UserIdTypeTest extends TestCase
{
    public function testReadsAStoredIdAsAUserId(): void
    {
        // Given
        $id = new UserId();

        // When
        $read = new UserIdType()->convertToPHPValue($id->toRfc4122(), new PostgreSQLPlatform());

        // Then
        self::assertInstanceOf(UserId::class, $read);
        self::assertTrue($id->equals($read));
    }

    public function testReadsAnotherVersion7ObjectAsAUserId(): void
    {
        // Given
        $id = new UuidV7();

        // When
        $read = new UserIdType()->convertToPHPValue($id, new PostgreSQLPlatform());

        // Then
        self::assertInstanceOf(UserId::class, $read);
        self::assertTrue($id->equals($read));
    }

    #[DataProvider('unreadableValues')]
    public function testRefusesToReadAnythingButAVersion7Id(mixed $value): void
    {
        // Then / When
        $this->expectException(ConversionException::class);
        new UserIdType()->convertToPHPValue($value, new PostgreSQLPlatform());
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

    public function testStoresAUserIdAsItsCanonicalString(): void
    {
        // Given
        $id = new UserId();

        // When
        $stored = new UserIdType()->convertToDatabaseValue($id, new PostgreSQLPlatform());

        // Then
        self::assertSame($id->toRfc4122(), $stored);
    }

    #[DataProvider('foreignIds')]
    public function testRefusesToStoreAnotherKindOfId(mixed $value): void
    {
        // Then / When
        $this->expectException(ConversionException::class);
        new UserIdType()->convertToDatabaseValue($value, new PostgreSQLPlatform());
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
        $type = new UserIdType();
        $platform = new PostgreSQLPlatform();

        // When / Then
        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));
    }
}
