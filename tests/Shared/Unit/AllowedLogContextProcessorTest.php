<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Logging\AllowedLogContextProcessor;
use App\Shared\Logging\LogContextKey;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class AllowedLogContextProcessorTest extends TestCase
{
    public function testKeepsTheKeysOfTheLogCatalogue(): void
    {
        // Given
        $exception = new \RuntimeException();
        $context = [
            LogContextKey::Source->value => 'apod',
            LogContextKey::HttpStatus->value => 503,
            LogContextKey::UserId->value => '0b5e4a52-6a8f-4d4b-9a0c-3e1f2d7c8b90',
            LogContextKey::Exception->value => $exception,
        ];

        // When
        $record = new AllowedLogContextProcessor()(self::record($context));

        // Then
        self::assertSame($context, $record->context);
    }

    public function testMasksEveryOtherKey(): void
    {
        // When
        $record = new AllowedLogContextProcessor()(self::record([
            LogContextKey::Source->value => 'apod',
            'lat' => 52.2297,
            'payload' => ['email' => 'jan@example.com'],
            0 => 'positional',
        ]));

        // Then
        self::assertSame([
            LogContextKey::Source->value => 'apod',
            'lat' => AllowedLogContextProcessor::MASK,
            'payload' => AllowedLogContextProcessor::MASK,
            0 => AllowedLogContextProcessor::MASK,
        ], $record->context);
    }

    /**
     * @param array<mixed> $context
     */
    private static function record(array $context): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'briefing', Level::Warning, 'Source {source} fetch failed', $context);
    }
}
