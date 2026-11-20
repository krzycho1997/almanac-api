<?php

declare(strict_types=1);

namespace App\Tests\Identity\Unit\Doctrine;

use App\Identity\Doctrine\EmailType;
use App\Identity\Email;
use App\Identity\Exception\InvalidEmailException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\TestCase;

final class EmailTypeTest extends TestCase
{
    public function testStoresTheNormalizedAddress(): void
    {
        // When
        $stored = new EmailType()->convertToDatabaseValue(Email::fromString(' Ada@Example.com'), new PostgreSQLPlatform());

        // Then
        self::assertSame('ada@example.com', $stored);
    }

    public function testReadsAnAddressBackAsAnEmail(): void
    {
        // When
        $email = new EmailType()->convertToPHPValue('ada@example.com', new PostgreSQLPlatform());

        // Then
        self::assertEquals(Email::fromString('ada@example.com'), $email);
    }

    public function testKeepsNullBothWays(): void
    {
        // Given
        $type = new EmailType();
        $platform = new PostgreSQLPlatform();

        // When / Then
        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    public function testRefusesToStoreAPlainString(): void
    {
        // Then / When
        $this->expectException(ConversionException::class);
        new EmailType()->convertToDatabaseValue('ada@example.com', new PostgreSQLPlatform());
    }

    public function testRefusesToReadAStoredValueThatIsNotAnAddress(): void
    {
        // When
        $failure = null;
        try {
            new EmailType()->convertToPHPValue('  ', new PostgreSQLPlatform());
        } catch (ConversionException $failure) {
        }

        // Then
        self::assertInstanceOf(ConversionException::class, $failure);
        self::assertInstanceOf(InvalidEmailException::class, $failure->getPrevious());
    }

    public function testRefusesToReadANonString(): void
    {
        // Then / When
        $this->expectException(ConversionException::class);
        new EmailType()->convertToPHPValue(42, new PostgreSQLPlatform());
    }
}
