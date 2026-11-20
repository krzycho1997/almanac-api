<?php

declare(strict_types=1);

namespace App\Tests\Identity\Unit\Entity;

use App\Identity\Email;
use App\Identity\Entity\AccessToken;
use App\Identity\Entity\User;
use App\Identity\Exception\InvalidTokenLifetimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessTokenTest extends TestCase
{
    private const string NOW = '2026-11-18T12:00:00+00:00';
    private const string THIRTY_DAYS = 'P30D';
    private const string PLAIN_TOKEN_FORMAT = '/^[0-9a-f]{64}$/';

    public function testIssuesA256BitTokenAndKeepsOnlyItsHash(): void
    {
        // Given
        $now = new \DateTimeImmutable(self::NOW);

        // When
        $issued = AccessToken::issue(self::user($now), new \DateInterval(self::THIRTY_DAYS), $now);

        // Then
        self::assertMatchesRegularExpression(self::PLAIN_TOKEN_FORMAT, $issued->plainToken);
        self::assertSame(hash('sha256', $issued->plainToken), $issued->token->tokenHash);
    }

    public function testIssuesADifferentTokenEveryTime(): void
    {
        // Given
        $now = new \DateTimeImmutable(self::NOW);
        $user = self::user($now);
        $ttl = new \DateInterval(self::THIRTY_DAYS);

        // When
        $first = AccessToken::issue($user, $ttl, $now);
        $second = AccessToken::issue($user, $ttl, $now);

        // Then
        self::assertNotSame($first->plainToken, $second->plainToken);
        self::assertFalse($first->token->id->equals($second->token->id));
    }

    public function testExpiresAfterItsTimeToLive(): void
    {
        // Given
        $now = new \DateTimeImmutable(self::NOW);
        $user = self::user($now);

        // When
        $token = AccessToken::issue($user, new \DateInterval(self::THIRTY_DAYS), $now)->token;

        // Then
        self::assertSame($user, $token->user);
        self::assertEquals($now, $token->createdAt);
        self::assertEquals(new \DateTimeImmutable('2026-12-18T12:00:00+00:00'), $token->expiresAt);
        self::assertFalse($token->isExpired($now));
        self::assertFalse($token->isExpired($token->expiresAt->modify('-1 second')));
        self::assertTrue($token->isExpired($token->expiresAt));
    }

    public function testKeepsWholeSecondsAsTheDatabaseDoes(): void
    {
        // Given
        $now = new \DateTimeImmutable('2026-11-18T12:00:00.900000+00:00');

        // When
        $token = AccessToken::issue(self::user($now), new \DateInterval('PT1H'), $now)->token;

        // Then
        self::assertEquals(new \DateTimeImmutable('2026-11-18T12:00:00+00:00'), $token->createdAt);
        self::assertEquals(new \DateTimeImmutable('2026-11-18T13:00:00+00:00'), $token->expiresAt);
        self::assertTrue($token->isExpired(new \DateTimeImmutable('2026-11-18T13:00:00.500000+00:00')));
    }

    #[DataProvider('lifetimesThatAreNotPositive')]
    public function testRefusesALifetimeThatIsNotPositive(\DateInterval $ttl): void
    {
        // Given
        $now = new \DateTimeImmutable(self::NOW);

        // Then / When
        $this->expectException(InvalidTokenLifetimeException::class);
        AccessToken::issue(self::user($now), $ttl, $now);
    }

    /**
     * @return iterable<string, array{\DateInterval}>
     */
    public static function lifetimesThatAreNotPositive(): iterable
    {
        yield 'zero' => [new \DateInterval('PT0S')];

        $negative = new \DateInterval('PT1H');
        $negative->invert = 1;
        yield 'negative' => [$negative];
    }

    public function testHashesWithSha256(): void
    {
        // When
        $hash = AccessToken::hash('token');

        // Then
        self::assertSame('3c469e9d6c5875d37a43f353d4f88e61fcf812c66eee3457465a40b0da4153e0', $hash);
    }

    private static function user(\DateTimeImmutable $now): User
    {
        return new User(Email::fromString('ada@example.com'), $now);
    }
}
