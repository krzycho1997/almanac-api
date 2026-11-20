<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-require-extends KernelTestCase
 */
trait InteractsWithLogs
{
    private const string REQUEST_CHANNEL = 'request';
    private const string EXCEPTION = 'exception';

    protected static function logHandler(): TestHandler
    {
        foreach (self::getContainer()->get('logger')->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return $handler;
            }
        }

        self::fail('The test environment logs to no Monolog TestHandler.');
    }

    /**
     * @return list<Level>
     */
    protected static function loggedExceptionLevels(): array
    {
        $records = array_filter(
            self::logHandler()->getRecords(),
            static fn (LogRecord $record): bool => self::REQUEST_CHANNEL === $record->channel && isset($record->context[self::EXCEPTION]),
        );

        return array_values(array_map(static fn (LogRecord $record): Level => $record->level, $records));
    }

    /**
     * @return list<Level>
     */
    protected static function levelsFrom(Level $minimum): array
    {
        $levels = array_map(static fn (LogRecord $record): Level => $record->level, self::logHandler()->getRecords());

        return array_values(array_filter($levels, static fn (Level $level): bool => $minimum->includes($level)));
    }
}
