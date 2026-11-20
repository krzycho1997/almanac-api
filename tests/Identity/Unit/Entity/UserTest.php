<?php

declare(strict_types=1);

namespace App\Tests\Identity\Unit\Entity;

use App\Identity\Email;
use App\Identity\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testIsIdentifiedToSecurityByItsUuidRatherThanItsAddress(): void
    {
        // Given
        $user = self::user();

        // When
        $identifier = $user->getUserIdentifier();

        // Then
        self::assertSame($user->id->toRfc4122(), $identifier);
    }

    public function testHasTheOrdinaryUserRoleOnly(): void
    {
        // When
        $roles = self::user()->getRoles();

        // Then
        self::assertSame(['ROLE_USER'], $roles);
    }

    private static function user(): User
    {
        return new User(Email::fromString('ada@example.com'), new \DateTimeImmutable('2026-11-18T12:00:00+00:00'));
    }
}
