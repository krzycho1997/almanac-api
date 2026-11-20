<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\InputValidationFailedException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Exception\NamespaceNotFoundException;
use Symfony\Component\Console\Exception\RuntimeException;

#[AsMonologProcessor(channel: self::CHANNEL)]
final readonly class ConsoleInputErrorLevelProcessor
{
    private const string CHANNEL = 'console';
    private const string EXCEPTION = 'exception';
    private const array INPUT_ERRORS = [
        CommandNotFoundException::class,
        NamespaceNotFoundException::class,
        InvalidArgumentException::class,
        InvalidOptionException::class,
        MissingInputException::class,
        InputValidationFailedException::class,
        RuntimeException::class,
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        if (self::isInputError($record->context[self::EXCEPTION] ?? null)) {
            return $record->with(level: Level::Notice);
        }

        return $record;
    }

    private static function isInputError(mixed $exception): bool
    {
        if ($exception instanceof \Throwable) {
            return \in_array($exception::class, self::INPUT_ERRORS, true);
        }

        return false;
    }
}
