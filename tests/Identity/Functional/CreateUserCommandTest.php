<?php

declare(strict_types=1);

namespace App\Tests\Identity\Functional;

use App\Identity\Email;
use App\Identity\Entity\AccessToken;
use App\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Exception\InputValidationFailedException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Output\OutputInterface;

final class CreateUserCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string NOW = '2026-11-18T12:00:00+00:00';
    private const string COMMAND = 'almanac:user:create';
    private const string ADDRESS = 'ada@example.com';
    private const string TOKEN = '/^[0-9a-f]{64}$/';
    private const string LINE_BREAK = "\n";

    protected function setUp(): void
    {
        parent::setUp();

        self::mockTime(new \DateTimeImmutable(self::NOW));
    }

    public function testPrintsTheNewTokenOnceOnTheLastLineWithItsOwnerAndExpiry(): void
    {
        // When
        $result = self::runCommand(self::COMMAND, ['email' => self::ADDRESS]);

        // Then
        $this->assertCommandIsSuccessful($result, $result->getDisplay());
        $token = self::lastLineOf($result->getDisplay());
        self::assertMatchesRegularExpression(self::TOKEN, $token);
        self::assertSame(1, substr_count($result->getDisplay(), $token));
        self::assertSame([AccessToken::hash($token)], self::storedHashes());
        self::assertStringContainsString('2026-12-18T12:00:00+00:00', $result->getDisplay());
        self::assertStringContainsString(self::user()->id->toRfc4122(), $result->getDisplay());
    }

    public function testPrintsOnlyTheTokenWhenQuiet(): void
    {
        // When
        $result = self::runCommand(self::COMMAND, ['email' => self::ADDRESS], verbosity: OutputInterface::VERBOSITY_QUIET);

        // Then
        $this->assertCommandIsSuccessful($result);
        self::assertMatchesRegularExpression(self::TOKEN, trim($result->getDisplay()));
        self::assertSame([AccessToken::hash(trim($result->getDisplay()))], self::storedHashes());
    }

    public function testRefusesToRunSilentlyBeforeRevokingAnything(): void
    {
        // Given
        $first = self::lastLineOf(self::runCommand(self::COMMAND, ['email' => self::ADDRESS])->getDisplay());

        // When
        $failure = self::failureOf(['email' => self::ADDRESS], OutputInterface::VERBOSITY_SILENT);

        // Then
        self::assertInstanceOf(InvalidOptionException::class, $failure);
        self::assertSame([AccessToken::hash($first)], self::storedHashes());
    }

    public function testRunningItAgainRevokesTheEarlierToken(): void
    {
        // Given
        $first = self::lastLineOf(self::runCommand(self::COMMAND, ['email' => self::ADDRESS])->getDisplay());

        // When
        $result = self::runCommand(self::COMMAND, ['email' => 'ADA@example.com', '--ttl' => '12h']);

        // Then
        $this->assertCommandIsSuccessful($result, $result->getDisplay());
        $second = self::lastLineOf($result->getDisplay());
        self::assertNotSame($first, $second);
        self::assertSame([AccessToken::hash($second)], self::storedHashes());
        self::assertStringContainsString('2026-11-19T00:00:00+00:00', $result->getDisplay());
    }

    #[DataProvider('lifetimes')]
    public function testAcceptsLifetimesFromOneHourToOneYear(string $ttl, string $expiresAt): void
    {
        // When
        $result = self::runCommand(self::COMMAND, ['email' => self::ADDRESS, '--ttl' => $ttl]);

        // Then
        $this->assertCommandIsSuccessful($result, $result->getDisplay());
        self::assertStringContainsString($expiresAt, $result->getDisplay());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lifetimes(): iterable
    {
        yield 'one hour' => ['1h', '2026-11-18T13:00:00+00:00'];
        yield 'a year in hours' => ['8760h', '2027-11-18T12:00:00+00:00'];
        yield 'a year in days' => ['365d', '2027-11-18T12:00:00+00:00'];
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInputWithoutCreatingAnything(array $input, string $message): void
    {
        // When
        $failure = self::failureOf($input, OutputInterface::VERBOSITY_NORMAL);

        // Then
        self::assertInstanceOf(InputValidationFailedException::class, $failure);
        self::assertSame($message, $failure->getMessage());
        self::assertSame([], self::entityManager()->getRepository(User::class)->findAll());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidInputs(): iterable
    {
        $format = '--ttl: Give the lifetime in hours (12h) or days (30d).';
        $range = '--ttl: The lifetime must be between 1h and 365d.';

        yield 'not an address' => [['email' => 'ada'], 'email: This value is not a valid email address.'];
        yield 'blank address' => [['email' => ''], 'email: This value should not be blank.'];
        yield 'address with a leading space' => [['email' => ' ada@example.com'], 'email: This value is not a valid email address.'];
        yield 'address without a top-level domain' => [['email' => 'ada@localhost'], 'email: This value is not a valid email address.'];
        yield 'address too long' => [['email' => str_repeat('a', 243).'@example.com'], 'email: This value is too long. It should have 254 characters or less.'];
        yield 'empty lifetime' => [['email' => self::ADDRESS, '--ttl' => ''], $format];
        yield 'zero hours' => [['email' => self::ADDRESS, '--ttl' => '0h'], $format];
        yield 'minutes' => [['email' => self::ADDRESS, '--ttl' => '30m'], $format];
        yield 'no unit' => [['email' => self::ADDRESS, '--ttl' => '30'], $format];
        yield 'over a year in days' => [['email' => self::ADDRESS, '--ttl' => '366d'], $range];
        yield 'over a year in hours' => [['email' => self::ADDRESS, '--ttl' => '8761h'], $range];
        yield 'five-digit hours' => [['email' => self::ADDRESS, '--ttl' => '99999h'], $range];
        yield 'lifetime with a trailing line break' => [['email' => self::ADDRESS, '--ttl' => "12h\n"], $format];
    }

    /**
     * @param array<string, string> $input
     */
    private static function failureOf(array $input, int $verbosity): ?\Throwable
    {
        try {
            self::runCommand(self::COMMAND, $input, verbosity: $verbosity);
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private static function lastLineOf(string $display): string
    {
        $lines = explode(self::LINE_BREAK, trim($display));

        return trim($lines[array_key_last($lines)]);
    }

    private static function user(): User
    {
        $user = self::entityManager()->getRepository(User::class)->findOneBy(['email' => Email::fromString(self::ADDRESS)]);
        self::assertInstanceOf(User::class, $user);

        return $user;
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

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
