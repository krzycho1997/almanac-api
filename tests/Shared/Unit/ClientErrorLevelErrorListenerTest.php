<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\ErrorHandler\ClientErrorLevelErrorListener;
use App\Tests\Support\ErrorHandler\ClientErrorImplementingTheErrorLevel;
use App\Tests\Support\ErrorHandler\ClientErrorInheritingTheErrorLevel;
use App\Tests\Support\ErrorHandler\ClientErrorLoggedAsError;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ClientErrorLevelErrorListenerTest extends TestCase
{
    /**
     * @param array<class-string, array{log_level: string|null, status_code: int<100, 599>|null, log_channel: string|null}> $exceptionsMapping
     */
    #[DataProvider('exceptions')]
    public function testLowersOnlyTheFrameworkDefaultLevelOfClientErrors(\Throwable $exception, array $exceptionsMapping, Level $expectedLevel): void
    {
        // Given
        $handler = new TestHandler();
        $listener = new ClientErrorLevelErrorListener(null, new Logger('request', [$handler]), false, $exceptionsMapping);
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $exception);

        // When
        $listener->logKernelException($event);

        // Then
        self::assertSame([$expectedLevel], array_map(static fn ($record): Level => $record->level, $handler->getRecords()));
    }

    /**
     * @return iterable<string, array{\Throwable, array<class-string, array{log_level: string|null, status_code: int<100, 599>|null, log_channel: string|null}>, Level}>
     */
    public static function exceptions(): iterable
    {
        yield 'client error class' => [new NotFoundHttpException(), [], Level::Notice];
        yield 'plain client error' => [new HttpException(Response::HTTP_I_AM_A_TEAPOT), [], Level::Notice];
        yield 'highest client error' => [new HttpException(499), [], Level::Notice];
        yield 'domain exception mapped to a client error' => [new \DomainException(), [\DomainException::class => ['log_level' => null, 'status_code' => Response::HTTP_NOT_FOUND, 'log_channel' => null]], Level::Notice];
        yield 'explicitly configured level' => [new AccessDeniedHttpException(), [AccessDeniedHttpException::class => ['log_level' => LogLevel::WARNING, 'status_code' => null, 'log_channel' => null]], Level::Warning];
        yield 'client error explicitly logged at error' => [new AccessDeniedHttpException(), [AccessDeniedHttpException::class => ['log_level' => LogLevel::ERROR, 'status_code' => null, 'log_channel' => null]], Level::Error];
        yield 'domain exception mapped to a client error logged at error' => [new \DomainException(), [\DomainException::class => ['log_level' => LogLevel::ERROR, 'status_code' => Response::HTTP_NOT_FOUND, 'log_channel' => null]], Level::Error];
        yield 'client error with an error level attribute' => [new ClientErrorLoggedAsError(), [], Level::Error];
        yield 'client error inheriting an error level attribute' => [new ClientErrorInheritingTheErrorLevel(), [], Level::Error];
        yield 'client error implementing an error level attribute' => [new ClientErrorImplementingTheErrorLevel(), [], Level::Error];
        yield 'client error caused by an exception without a level' => [new NotFoundHttpException(previous: new \RuntimeException()), [], Level::Notice];
        yield 'server error' => [new HttpException(Response::HTTP_SERVICE_UNAVAILABLE), [], Level::Critical];
        yield 'server error explicitly logged at error' => [new ServiceUnavailableHttpException(), [ServiceUnavailableHttpException::class => ['log_level' => LogLevel::ERROR, 'status_code' => null, 'log_channel' => null]], Level::Error];
        yield 'non-HTTP exception' => [new \RuntimeException(), [], Level::Critical];
        yield 'non-HTTP exception explicitly logged at error' => [new \RuntimeException(), [\RuntimeException::class => ['log_level' => LogLevel::ERROR, 'status_code' => null, 'log_channel' => null]], Level::Error];
    }
}
