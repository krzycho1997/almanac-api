<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\Console\Exception\InputValidationFailedException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsMonologProcessor(channel: self::CHANNEL)]
final class ConsoleInputRedactingProcessor
{
    private const string CHANNEL = 'console';
    private const string COMMAND = 'command';
    private const string MESSAGE = 'message';
    private const string EXCEPTION = 'exception';
    private const string APPLICATION_NAMESPACE = 'App\\';
    private const string LOCATION = '%s:%d';

    private ?string $commandName = null;

    #[AsEventListener(ConsoleEvents::COMMAND)]
    #[AsEventListener(ConsoleEvents::ERROR)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function remember(ConsoleEvent $event): void
    {
        $this->commandName = $event->getCommand()?->getName();
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->withoutInput($record->context));
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private function withoutInput(array $context): array
    {
        $exception = $context[self::EXCEPTION] ?? null;

        if ($exception instanceof \Throwable) {
            return self::withoutExceptionText($exception, $this->withCommandName($context));
        }

        return $this->withCommandName($context);
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private function withCommandName(array $context): array
    {
        if (\array_key_exists(self::COMMAND, $context)) {
            return [self::COMMAND => $this->commandName ?? AllowedLogContextProcessor::MASK] + $context;
        }

        return $context;
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    /**
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private static function withoutExceptionText(\Throwable $exception, array $context): array
    {
        if (self::hasFixedText($exception)) {
            return $context;
        }

        return [self::EXCEPTION => self::described($exception), self::MESSAGE => $exception::class] + $context;
    }

    private static function hasFixedText(?\Throwable $exception): bool
    {
        if (null === $exception) {
            return true;
        }

        if (self::isWrittenByUs($exception)) {
            return self::hasFixedText($exception->getPrevious());
        }

        return false;
    }

    private static function isWrittenByUs(\Throwable $exception): bool
    {
        if ($exception instanceof InputValidationFailedException) {
            return true;
        }

        return str_starts_with($exception::class, self::APPLICATION_NAMESPACE);
    }

    /**
     * @return array{class: class-string, code: int|string, file: string, trace: list<string>}
     */
    private static function described(\Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'code' => $exception->getCode(),
            'file' => \sprintf(self::LOCATION, $exception->getFile(), $exception->getLine()),
            'trace' => array_map(self::location(...), $exception->getTrace()),
        ];
    }

    /**
     * @param array{file?: string, line?: int} $frame
     */
    private static function location(array $frame): string
    {
        return \sprintf(self::LOCATION, $frame['file'] ?? '', $frame['line'] ?? 0);
    }
}
