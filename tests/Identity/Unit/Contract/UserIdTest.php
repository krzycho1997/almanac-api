<?php

declare(strict_types=1);

namespace App\Tests\Identity\Unit\Contract;

use App\Identity\Contract\UserId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV4;
use Symfony\Component\Uid\UuidV7;

final class UserIdTest extends TestCase
{
    private const string VERSION_7 = '7';
    private const int VERSION_NIBBLE = 14;

    public function testGeneratesTimeOrderedVersion7Ids(): void
    {
        // When
        $first = new UserId();
        $second = new UserId();

        // Then
        self::assertSame(self::VERSION_7, $first->toRfc4122()[self::VERSION_NIBBLE]);
        self::assertLessThan(0, $first->compare($second));
    }

    public function testReadsItsOwnStringBack(): void
    {
        // Given
        $id = new UserId();

        // When
        $read = UserId::fromString($id->toRfc4122());

        // Then
        self::assertTrue($id->equals($read));
    }

    public function testRejectsAnotherUuidVersion(): void
    {
        // Then / When
        $this->expectException(\InvalidArgumentException::class);
        UserId::fromString(new UuidV4()->toRfc4122());
    }

    public function testAcceptsAnyVersion7Uuid(): void
    {
        // Given
        $generic = new UuidV7();

        // When
        $read = UserId::fromString($generic->toRfc4122());

        // Then
        self::assertTrue($generic->equals($read));
    }
}
