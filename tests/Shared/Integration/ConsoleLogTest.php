<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration;

use App\Identity\Exception\InvalidTokenLifetimeException;
use App\Shared\Logging\AllowedLogContextProcessor;
use App\Tests\Support\Command\FailingCommand;
use App\Tests\Support\InteractsWithLogs;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ConsoleLogTest extends KernelTestCase
{
    use InteractsWithLogs;

    private const string CHANNEL = 'console';
    private const string COMMAND = 'command';
    private const string PERSONAL_INPUT = 'Ada Lovelace';

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('failingRuns')]
    public function testLogsAFailedCommandWithoutItsInput(array $input, string $loggedCommand): void
    {
        // Given
        $tester = self::tester();

        // When
        $exitCode = $tester->run($input, ['interactive' => false]);

        // Then
        self::assertSame(Command::FAILURE, $exitCode);
        $records = self::recordsOf(self::CHANNEL);
        self::assertNotSame([], $records);
        foreach ($records as $record) {
            self::assertIsString($record->formatted);
            self::assertStringNotContainsString(self::PERSONAL_INPUT, $record->formatted);
        }
        self::assertSame([$loggedCommand], self::loggedCommands($records));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function failingRuns(): iterable
    {
        yield 'known command' => [['command' => FailingCommand::NAME, 'value' => self::PERSONAL_INPUT], FailingCommand::NAME];
        yield 'personal data as the command name' => [['command' => self::PERSONAL_INPUT], AllowedLogContextProcessor::MASK];
        yield 'personal data as a namespace to list' => [['command' => 'list', 'namespace' => self::PERSONAL_INPUT], 'list'];
        yield 'personal data as an output format' => [['command' => 'list', '--format' => self::PERSONAL_INPUT], 'list'];
    }

    public function testForgetsTheNameOfTheCommandThatRanBefore(): void
    {
        // Given
        $tester = self::tester();
        $tester->run(['command' => FailingCommand::NAME, 'value' => self::PERSONAL_INPUT], ['interactive' => false]);
        self::logHandler()->clear();

        // When
        $tester->run(['command' => self::PERSONAL_INPUT], ['interactive' => false]);

        // Then
        self::assertSame([AllowedLogContextProcessor::MASK], self::loggedCommands(self::recordsOf(self::CHANNEL)));
    }

    public function testKeepsTheFixedTextOfAnApplicationException(): void
    {
        // Given
        $logger = self::getContainer()->get('monolog.logger.console');
        $exception = new InvalidTokenLifetimeException('An access token must live for a positive amount of time.');

        // When
        $logger->critical('Failed: {message}', ['exception' => $exception, 'message' => $exception->getMessage()]);

        // Then
        $records = self::recordsOf(self::CHANNEL);
        $last = end($records);
        self::assertInstanceOf(LogRecord::class, $last);
        self::assertSame($exception, $last->context['exception']);
        self::assertSame($exception->getMessage(), $last->context['message']);
    }

    public function testDropsTheTextOfAnApplicationExceptionCausedByAForeignOne(): void
    {
        // Given
        $logger = self::getContainer()->get('monolog.logger.console');
        $exception = new InvalidTokenLifetimeException('Fixed text.', previous: new \RuntimeException(self::PERSONAL_INPUT));

        // When
        $logger->critical('Failed: {message}', ['exception' => $exception, 'message' => $exception->getMessage()]);

        // Then
        $records = self::recordsOf(self::CHANNEL);
        $last = end($records);
        self::assertInstanceOf(LogRecord::class, $last);
        self::assertIsString($last->formatted);
        self::assertStringNotContainsString(self::PERSONAL_INPUT, $last->formatted);
    }

    public function testKeepsTheTextOfAnInputValidationFailure(): void
    {
        // Given
        $tester = self::tester();

        // When
        $tester->run(['command' => 'almanac:user:create', 'email' => 'ada@example.com', '--ttl' => '30m'], ['interactive' => false]);

        // Then
        $records = self::recordsOf(self::CHANNEL);
        $failure = array_values(array_filter($records, static fn (LogRecord $record): bool => isset($record->context['exception'])));
        self::assertCount(1, $failure);
        self::assertSame('--ttl: Give the lifetime in hours (12h) or days (30d).', $failure[0]->context['message']);
        self::assertSame(Level::Notice, $failure[0]->level);
    }

    public function testLogsAFailureOfTheCommandItselfAsCritical(): void
    {
        // Given
        $tester = self::tester();

        // When
        $tester->run(['command' => FailingCommand::NAME, 'value' => 'x'], ['interactive' => false]);

        // Then
        $records = self::recordsOf(self::CHANNEL);
        $failure = array_values(array_filter($records, static fn (LogRecord $record): bool => isset($record->context['exception'])));
        self::assertCount(1, $failure);
        self::assertSame(Level::Critical, $failure[0]->level);
    }

    public function testLeavesRecordsWithoutACommandAlone(): void
    {
        // Given
        $logger = self::getContainer()->get('monolog.logger.console');

        // When
        $logger->warning('Nothing about a command', ['other' => 'value']);

        // Then
        $records = self::recordsOf(self::CHANNEL);
        $last = end($records);
        self::assertInstanceOf(LogRecord::class, $last);
        self::assertSame(['other' => 'value'], $last->context);
        self::assertSame(Level::Warning, $last->level);
    }

    /**
     * @param list<LogRecord> $records
     *
     * @return list<string>
     */
    private static function loggedCommands(array $records): array
    {
        $commands = [];
        foreach ($records as $record) {
            $command = $record->context[self::COMMAND] ?? null;
            if (\is_string($command)) {
                $commands[$command] = $command;
            }
        }

        return array_values($commands);
    }

    private static function tester(): ApplicationTester
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }
}
