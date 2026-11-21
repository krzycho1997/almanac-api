<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration;

use App\Tests\Support\InteractsWithLogs;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ExceptionTraceLogTest extends KernelTestCase
{
    use InteractsWithLogs;

    private const string FRAMEWORK_LOGGER = 'monolog.logger.request';
    private const string MESSAGE = 'Uncaught PHP Exception';
    private const string CONTEXT = 'context';
    private const string TRACE = 'trace';
    private const string PREVIOUS = 'previous';
    private const string CLASS_KEY = 'class';

    public function testLogsAClientErrorAndItsPreviousExceptionsWithoutTraces(): void
    {
        // Given
        $exception = new NotFoundHttpException('No route', new \RuntimeException('Router miss'));

        // When
        self::frameworkLogger()->notice(self::MESSAGE, [self::EXCEPTION => $exception]);

        // Then
        $written = self::writtenExceptions()[0];
        self::assertSame(NotFoundHttpException::class, $written[self::CLASS_KEY]);
        self::assertArrayNotHasKey(self::TRACE, $written);
        self::assertIsArray($written[self::PREVIOUS]);
        self::assertSame(\RuntimeException::class, $written[self::PREVIOUS][self::CLASS_KEY]);
        self::assertArrayNotHasKey(self::TRACE, $written[self::PREVIOUS]);
    }

    public function testLogsTooManyRequestsWithoutTrace(): void
    {
        // When
        self::frameworkLogger()->notice(self::MESSAGE, [self::EXCEPTION => new TooManyRequestsHttpException(60)]);

        // Then
        self::assertArrayNotHasKey(self::TRACE, self::writtenExceptions()[0]);
    }

    /**
     * @param LogLevel::* $level
     */
    #[DataProvider('failuresWithTraces')]
    public function testLogsOtherFailuresWithTheirTrace(string $level, \Throwable $exception): void
    {
        // When
        self::frameworkLogger()->log($level, self::MESSAGE, [self::EXCEPTION => $exception]);

        // Then
        self::assertArrayHasKey(self::TRACE, self::writtenExceptions()[0]);
    }

    /**
     * @return iterable<string, array{LogLevel::*, \Throwable}>
     */
    public static function failuresWithTraces(): iterable
    {
        yield 'internal server error' => [LogLevel::CRITICAL, new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR)];
        yield 'internal server error logged at notice' => [LogLevel::NOTICE, new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR)];
        yield 'service unavailable' => [LogLevel::CRITICAL, new ServiceUnavailableHttpException()];
        yield 'plain exception' => [LogLevel::NOTICE, new \RuntimeException('Broken')];
        yield 'client error explicitly logged at warning' => [LogLevel::WARNING, new ConflictHttpException('Taken')];
    }

    public function testKeepsTracesForTheRecordsAfterAClientError(): void
    {
        // Given
        $logger = self::frameworkLogger();
        $logger->notice(self::MESSAGE, [self::EXCEPTION => new NotFoundHttpException()]);
        $logger->warning(self::MESSAGE, [self::EXCEPTION => new ConflictHttpException()]);

        // When
        $logger->notice(self::MESSAGE, [self::EXCEPTION => new \RuntimeException('Broken')]);

        // Then
        self::assertSame([false, true, true], array_map(static fn (array $written): bool => isset($written[self::TRACE]), self::writtenExceptions()));
    }

    private static function frameworkLogger(): LoggerInterface
    {
        return self::getContainer()->get(self::FRAMEWORK_LOGGER);
    }

    /**
     * @return list<array<mixed>>
     */
    private static function writtenExceptions(): array
    {
        $records = self::recordsOf(self::REQUEST_CHANNEL);
        self::assertNotEmpty($records);

        return array_map(self::writtenException(...), $records);
    }

    /**
     * @return array<mixed>
     */
    private static function writtenException(LogRecord $record): array
    {
        self::assertIsString($record->formatted);
        $written = json_decode($record->formatted, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($written);
        self::assertIsArray($written[self::CONTEXT]);
        $exception = $written[self::CONTEXT][self::EXCEPTION];
        self::assertIsArray($exception);

        return $exception;
    }
}
