<?php

declare(strict_types=1);

namespace App\Tests\Identity\Integration;

use App\Identity\AccessTokenIssuer;
use App\Identity\Email;
use App\Identity\Security\AccessTokenHandler;
use App\Tests\Support\InteractsWithLogs;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class AccessTokenHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLogs;

    private const string NOW = '2026-11-18T12:00:00+00:00';
    private const string ONE_HOUR = 'PT1H';
    private const string ADDRESS = 'ada@example.com';
    private const string DOCTRINE_CHANNEL = 'doctrine';
    private const string EXECUTING_STATEMENT = 'Executing statement';
    private const string UNKNOWN_TOKEN = '0000000000000000000000000000000000000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        self::mockTime(new \DateTimeImmutable(self::NOW));
    }

    public function testIdentifiesTheOwnerOfAValidTokenByItsId(): void
    {
        // Given
        $issued = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::ONE_HOUR));

        // When
        $badge = self::handler()->getUserBadgeFrom($issued->plainToken);

        // Then
        $userId = $issued->token->user->id->toRfc4122();
        self::assertSame($userId, $badge->getUserIdentifier());
        self::assertSame($userId, $badge->getUser()->getUserIdentifier());
    }

    #[DataProvider('unknownTokens')]
    public function testRejectsATokenItNeverIssued(string $token): void
    {
        // Then / When
        $this->expectException(BadCredentialsException::class);
        self::handler()->getUserBadgeFrom($token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownTokens(): iterable
    {
        yield 'well formed' => [self::UNKNOWN_TOKEN];
        yield 'upper case' => [strtoupper(hash('sha256', 'token'))];
        yield 'too short' => ['abc'];
        yield 'one megabyte' => [str_repeat('a', 1024 * 1024)];
    }

    /**
     * @param \Closure(string): string $alteration
     */
    #[DataProvider('shapesItNeverIssues')]
    public function testDoesNotLookUpATokenThatCannotHaveBeenIssued(\Closure $alteration): void
    {
        // Given
        $issued = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::ONE_HOUR));
        $queries = self::queryCount();

        // When
        $failure = null;
        try {
            self::handler()->getUserBadgeFrom($alteration($issued->plainToken));
        } catch (BadCredentialsException $failure) {
        }

        // Then
        self::assertInstanceOf(BadCredentialsException::class, $failure);
        self::assertSame($queries, self::queryCount());
    }

    public function testRejectsATokenFromTheMomentItExpires(): void
    {
        // Given
        $issued = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::ONE_HOUR));
        self::mockTime($issued->token->expiresAt);

        // Then / When
        $this->expectException(BadCredentialsException::class);
        self::handler()->getUserBadgeFrom($issued->plainToken);
    }

    public function testAcceptsATokenUntilItExpires(): void
    {
        // Given
        $issued = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::ONE_HOUR));
        self::mockTime($issued->token->expiresAt->modify('-1 second'));

        // When
        $badge = self::handler()->getUserBadgeFrom($issued->plainToken);

        // Then
        self::assertSame($issued->token->user->id->toRfc4122(), $badge->getUserIdentifier());
    }

    /**
     * @return iterable<string, array{\Closure(string): string}>
     */
    public static function shapesItNeverIssues(): iterable
    {
        yield 'upper case' => [strtoupper(...)];
        yield 'trailing line break' => [static fn (string $token): string => $token."\n"];
        yield 'one character more' => [static fn (string $token): string => $token.'0'];
    }

    private static function queryCount(): int
    {
        $statements = array_filter(
            self::recordsOf(self::DOCTRINE_CHANNEL),
            static fn (LogRecord $record): bool => str_starts_with($record->message, self::EXECUTING_STATEMENT),
        );

        return \count($statements);
    }

    private static function issuer(): AccessTokenIssuer
    {
        return self::getContainer()->get(AccessTokenIssuer::class);
    }

    private static function handler(): AccessTokenHandler
    {
        return self::getContainer()->get(AccessTokenHandler::class);
    }
}
