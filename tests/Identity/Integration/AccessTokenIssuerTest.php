<?php

declare(strict_types=1);

namespace App\Tests\Identity\Integration;

use App\Identity\AccessTokenIssuer;
use App\Identity\Email;
use App\Identity\Entity\AccessToken;
use App\Identity\Entity\User;
use App\Shared\Logging\LogContextKey;
use App\Tests\Support\Identity\FailingFlush;
use App\Tests\Support\InteractsWithLogs;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class AccessTokenIssuerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLogs;

    private const string NOW = '2026-11-18T12:00:00+00:00';
    private const string CHANNEL = 'identity';
    private const string DOCTRINE_CHANNEL = 'doctrine';
    private const string ADDRESS = 'ada@example.com';
    private const string OTHER_ADDRESS = 'bob@example.com';
    private const string TRY_ADDRESS_LOCK = "SELECT pg_try_advisory_xact_lock(hashtext('identity_user.email'), hashtext(:email))";
    private const string EXECUTING_STATEMENT = 'Executing statement: ';
    private const string ADDRESS_LOCK = 'pg_advisory_xact_lock';
    private const string USER_LOOKUP = 'FROM identity_user';
    private const string DAMA_CONNECTION_KEY = 'dama.connection_key';
    private const string THIRTY_DAYS = 'P30D';
    private const string ONE_HOUR = 'PT1H';

    protected function setUp(): void
    {
        parent::setUp();

        self::mockTime(new \DateTimeImmutable(self::NOW));
    }

    public function testCreatesAUserWithItsFirstToken(): void
    {
        // When
        $issued = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));

        // Then
        $users = self::entityManager()->getRepository(User::class)->findAll();
        self::assertCount(1, $users);
        self::assertSame(self::ADDRESS, $users[0]->email->value);
        self::assertSame([AccessToken::hash($issued->plainToken)], self::storedHashes());
        self::assertEquals(new \DateTimeImmutable('2026-12-18T12:00:00+00:00'), $issued->token->expiresAt);
    }

    public function testRotationReplacesEveryTokenOfTheSameUser(): void
    {
        // Given
        $first = self::issuer()->issueFor(Email::fromString('Ada@Example.com'), new \DateInterval(self::THIRTY_DAYS));

        // When
        $second = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::ONE_HOUR));

        // Then
        self::assertCount(1, self::entityManager()->getRepository(User::class)->findAll());
        self::assertTrue($first->token->user->id->equals($second->token->user->id));
        self::assertSame([AccessToken::hash($second->plainToken)], self::storedHashes());
        self::assertEquals(new \DateTimeImmutable('2026-11-18T13:00:00+00:00'), $second->token->expiresAt);
    }

    public function testRotationRevokesEveryEarlierTokenOfThatUserOnly(): void
    {
        // Given
        $ada = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));
        self::addTokens($ada->token->user, 2);
        $bob = self::issuer()->issueFor(Email::fromString(self::OTHER_ADDRESS), new \DateInterval(self::THIRTY_DAYS));

        // When
        $rotated = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));

        // Then
        $records = self::recordsOf(self::CHANNEL);
        self::assertSame(3, end($records)->context[LogContextKey::RevokedCount->value] ?? null);
        self::assertEqualsCanonicalizing(
            [AccessToken::hash($rotated->plainToken), AccessToken::hash($bob->plainToken)],
            self::storedHashes(),
        );
    }

    #[DataProvider('addresses')]
    public function testHoldsALockOnTheAddressInsideTheTransaction(string $address): void
    {
        // Given
        self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));
        $otherSession = self::otherSession();

        // When
        self::issuer()->issueFor(Email::fromString($address), new \DateInterval(self::THIRTY_DAYS));

        // Then
        try {
            self::assertFalse($otherSession->fetchOne(self::TRY_ADDRESS_LOCK, ['email' => self::ADDRESS]));
            self::assertTrue($otherSession->fetchOne(self::TRY_ADDRESS_LOCK, ['email' => self::OTHER_ADDRESS]));
        } finally {
            $otherSession->close();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addresses(): iterable
    {
        yield 'rotation' => [self::ADDRESS];
        yield 'rotation through another spelling' => ['ADA@example.com'];
    }

    public function testHoldsALockOnANewAddressBeforeTheUserExists(): void
    {
        // Given
        $otherSession = self::otherSession();

        // When
        self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));

        // Then
        try {
            self::assertFalse($otherSession->fetchOne(self::TRY_ADDRESS_LOCK, ['email' => self::ADDRESS]));
        } finally {
            $otherSession->close();
        }
    }

    public function testLocksTheAddressBeforeLookingTheUserUp(): void
    {
        // When
        self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));

        // Then
        $statements = self::executedStatements();
        $lock = self::firstIndexContaining($statements, self::ADDRESS_LOCK);
        $lookup = self::firstIndexContaining($statements, self::USER_LOOKUP);
        self::assertLessThan($lookup, $lock);
    }

    public function testLogsNothingWhenTheTransactionFails(): void
    {
        // Given
        self::entityManager()->getEventManager()->addEventListener(Events::onFlush, new FailingFlush());

        // When
        $failure = null;
        try {
            self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));
        } catch (\RuntimeException $failure) {
        }

        // Then
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame([], self::recordsOf(self::CHANNEL));
    }

    public function testDeletingAUserDeletesItsTokens(): void
    {
        // Given
        $issued = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));
        $connection = self::getContainer()->get(Connection::class);

        // When
        $connection->executeStatement('DELETE FROM identity_user WHERE id = :id', ['id' => $issued->token->user->id->toRfc4122()]);

        // Then
        self::assertSame(0, $connection->fetchOne('SELECT count(*) FROM identity_access_token'));
    }

    public function testReadsTheStoredUserAndTokenBackThroughDoctrine(): void
    {
        // Given
        $issued = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));
        self::entityManager()->clear();

        // When
        $token = self::entityManager()->getRepository(AccessToken::class)->findOneBy(['tokenHash' => AccessToken::hash($issued->plainToken)]);

        // Then
        self::assertInstanceOf(AccessToken::class, $token);
        self::assertNotSame($issued->token, $token);
        self::assertTrue($issued->token->user->id->equals($token->user->id));
        self::assertEquals(Email::fromString(self::ADDRESS), $token->user->email);
        self::assertEquals($issued->token->expiresAt, $token->expiresAt);
        self::assertEquals($issued->token->createdAt, $token->createdAt);
        self::assertTrue($issued->token->id->equals($token->id));
    }

    public function testLogsTheNewUserByIdOnly(): void
    {
        // When
        $issued = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));

        // Then
        $records = self::recordsOf(self::CHANNEL);
        self::assertCount(1, $records);
        self::assertSame(Level::Notice, $records[0]->level);
        self::assertSame("User {$issued->token->user->id} created", $records[0]->message);
        self::assertSame($issued->token->user->id->toRfc4122(), $records[0]->context[LogContextKey::UserId->value]);
        self::assertEquals($issued->token->expiresAt, $records[0]->context[LogContextKey::ExpiresAt->value]);
        self::assertLogsHoldNeither(self::ADDRESS, $issued->plainToken);
    }

    public function testLogsTheRotationWithTheNumberOfRevokedTokens(): void
    {
        // Given
        $first = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));

        // When
        $second = self::issuer()->issueFor(Email::fromString(self::ADDRESS), new \DateInterval(self::THIRTY_DAYS));

        // Then
        $records = self::recordsOf(self::CHANNEL);
        self::assertCount(2, $records);
        self::assertSame(Level::Notice, $records[1]->level);
        self::assertSame("Access tokens rotated for user {$second->token->user->id}", $records[1]->message);
        self::assertSame($second->token->user->id->toRfc4122(), $records[1]->context[LogContextKey::UserId->value]);
        self::assertSame(1, $records[1]->context[LogContextKey::RevokedCount->value]);
        self::assertLogsHoldNeither(self::ADDRESS, $first->plainToken, $second->plainToken);
    }

    private static function assertLogsHoldNeither(string ...$secrets): void
    {
        $records = array_filter(self::logHandler()->getRecords(), self::isWrittenOutsideTests(...));

        self::assertNotSame([], $records);
        foreach ($records as $record) {
            self::assertIsString($record->formatted);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $record->formatted);
                self::assertStringNotContainsString(AccessToken::hash($secret), $record->formatted);
            }
        }
    }

    private static function isWrittenOutsideTests(LogRecord $record): bool
    {
        if (self::DOCTRINE_CHANNEL === $record->channel) {
            return Level::Warning->includes($record->level);
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function storedHashes(): array
    {
        return array_map(
            static fn (AccessToken $token): string => $token->tokenHash,
            self::entityManager()->getRepository(AccessToken::class)->findAll(),
        );
    }

    private static function addTokens(User $user, int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            self::entityManager()->persist(AccessToken::issue($user, new \DateInterval(self::THIRTY_DAYS), new \DateTimeImmutable(self::NOW))->token);
        }

        self::entityManager()->flush();
    }

    /**
     * @return list<string>
     */
    private static function executedStatements(): array
    {
        $statements = array_filter(
            self::recordsOf(self::DOCTRINE_CHANNEL),
            static fn (LogRecord $record): bool => str_starts_with($record->message, self::EXECUTING_STATEMENT),
        );

        return array_values(array_map(static fn (LogRecord $record): string => $record->message, $statements));
    }

    /**
     * @param list<string> $statements
     */
    private static function firstIndexContaining(array $statements, string $needle): int
    {
        foreach ($statements as $index => $statement) {
            if (str_contains($statement, $needle)) {
                return $index;
            }
        }

        self::fail("No executed statement contains {$needle}.");
    }

    private static function otherSession(): Connection
    {
        $params = self::getContainer()->get(Connection::class)->getParams();
        unset($params[self::DAMA_CONNECTION_KEY]);

        return DriverManager::getConnection($params);
    }

    private static function issuer(): AccessTokenIssuer
    {
        return self::getContainer()->get(AccessTokenIssuer::class);
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
