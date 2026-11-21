<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class ClientErrorJsonFormatter extends JsonFormatter
{
    private const Level LEVEL_WITH_TRACES = Level::Warning;
    private const string EXCEPTION = 'exception';

    /**
     * @return array<array<mixed>|bool|float|int|\stdClass|string|null>
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        if (self::isQuietClientError($record)) {
            return $this->normalizedWithoutStacktraces($record);
        }

        return parent::normalizeRecord($record);
    }

    private static function isQuietClientError(LogRecord $record): bool
    {
        if (self::LEVEL_WITH_TRACES->includes($record->level)) {
            return false;
        }

        $exception = $record->context[self::EXCEPTION] ?? null;

        return $exception instanceof \Throwable && ClientError::matches($exception);
    }

    /**
     * @return array<array<mixed>|bool|float|int|\stdClass|string|null>
     */
    private function normalizedWithoutStacktraces(LogRecord $record): array
    {
        $includeStacktraces = $this->includeStacktraces;
        $this->includeStacktraces(false);

        try {
            return parent::normalizeRecord($record);
        } finally {
            $this->includeStacktraces($includeStacktraces);
        }
    }
}
