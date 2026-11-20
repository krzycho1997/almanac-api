<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Contract;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryIdType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Exception\InvalidType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\UuidV4;
use Symfony\Component\Uid\UuidV5;
use Symfony\Component\Uid\UuidV7;

final class DiscoveryIdTypeTest extends TestCase
{
    private const string EXAMPLE = '2ed6657d-e927-568b-95e1-2665a8aea6a2';

    public function testReadsAStoredIdAsADiscoveryId(): void
    {
        // Given
        $id = new DiscoveryId(self::EXAMPLE);

        // When
        $read = new DiscoveryIdType()->convertToPHPValue($id->toRfc4122(), new PostgreSQLPlatform());

        // Then
        self::assertInstanceOf(DiscoveryId::class, $read);
        self::assertTrue($id->equals($read));
    }

    public function testReadsAnotherVersion5ObjectAsADiscoveryId(): void
    {
        // Given
        $id = new UuidV5(self::EXAMPLE);

        // When
        $read = new DiscoveryIdType()->convertToPHPValue($id, new PostgreSQLPlatform());

        // Then
        self::assertInstanceOf(DiscoveryId::class, $read);
        self::assertTrue($id->equals($read));
    }

    #[DataProvider('unreadableValues')]
    public function testRefusesToReadAnythingButAVersion5Id(mixed $value): void
    {
        // Then / When
        $this->expectException(ConversionException::class);
        new DiscoveryIdType()->convertToPHPValue($value, new PostgreSQLPlatform());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadableValues(): iterable
    {
        yield 'version 4 string' => [new UuidV4()->toRfc4122()];
        yield 'version 4 object' => [new UuidV4()];
        yield 'version 7 string' => [new UuidV7()->toRfc4122()];
        yield 'garbage' => ['not-a-uuid'];
        yield 'integer' => [42];
    }

    public function testStoresADiscoveryIdAsItsCanonicalString(): void
    {
        // Given
        $id = new DiscoveryId(self::EXAMPLE);

        // When
        $stored = new DiscoveryIdType()->convertToDatabaseValue($id, new PostgreSQLPlatform());

        // Then
        self::assertSame($id->toRfc4122(), $stored);
    }

    #[DataProvider('version5Strings')]
    public function testStoresAVersion5StringAsItsCanonicalString(string $value): void
    {
        // When
        $stored = new DiscoveryIdType()->convertToDatabaseValue($value, new PostgreSQLPlatform());

        // Then
        self::assertSame(self::EXAMPLE, $stored);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function version5Strings(): iterable
    {
        yield 'canonical' => [self::EXAMPLE];
        yield 'upper case' => [strtoupper(self::EXAMPLE)];
        yield 'base58' => [new UuidV5(self::EXAMPLE)->toBase58()];
    }

    #[DataProvider('foreignIds')]
    public function testRefusesToStoreAnotherKindOfId(mixed $value): void
    {
        // Then / When
        $this->expectException(ConversionException::class);
        new DiscoveryIdType()->convertToDatabaseValue($value, new PostgreSQLPlatform());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function foreignIds(): iterable
    {
        yield 'version 5 id of another kind' => [new UuidV5(self::EXAMPLE)];
        yield 'version 7 object' => [new UuidV7()];
        yield 'version 4 object' => [new UuidV4()];
        yield 'ulid' => [new Ulid()];
        yield 'version 4 string' => [new UuidV4()->toRfc4122()];
        yield 'integer' => [42];
    }

    public function testNamesItselfWhenItRefusesAValue(): void
    {
        // When
        $failure = self::failureOf(static fn (): mixed => new DiscoveryIdType()->convertToPHPValue('not-a-uuid', new PostgreSQLPlatform()));

        // Then
        self::assertInstanceOf(ConversionException::class, $failure);
        self::assertStringContainsString(DiscoveryIdType::NAME, $failure->getMessage());
    }

    public function testNamesItselfWhenItRefusesAnotherKindOfId(): void
    {
        // When
        $failure = self::failureOf(static fn (): mixed => new DiscoveryIdType()->convertToDatabaseValue(new UuidV7(), new PostgreSQLPlatform()));

        // Then
        self::assertInstanceOf(InvalidType::class, $failure);
        self::assertStringContainsString(DiscoveryIdType::NAME, $failure->getMessage());
    }

    public function testKeepsNullBothWays(): void
    {
        // Given
        $type = new DiscoveryIdType();
        $platform = new PostgreSQLPlatform();

        // When / Then
        self::assertNull($type->convertToPHPValue(null, $platform));
        self::assertNull($type->convertToDatabaseValue(null, $platform));
    }

    private static function failureOf(\Closure $conversion): ?\Throwable
    {
        try {
            $conversion();
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }
}
