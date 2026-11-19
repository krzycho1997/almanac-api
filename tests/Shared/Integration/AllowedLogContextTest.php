<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration;

use App\Shared\Logging\AllowedLogContextProcessor;
use App\Shared\Logging\LogContextKey;
use App\Tests\Support\InteractsWithLogs;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AllowedLogContextTest extends KernelTestCase
{
    use InteractsWithLogs;

    private const string UNLISTED_KEY = 'payload';
    private const string UNLISTED_VALUE = 'not-in-the-log-catalogue';

    #[DataProvider('ownChannels')]
    public function testMasksKeysOutsideTheLogCatalogueOnOurChannels(string $logger): void
    {
        // Given
        $channel = self::getContainer()->get($logger);
        self::assertInstanceOf(LoggerInterface::class, $channel);

        // When
        $channel->warning('Source {source} fetch failed', [LogContextKey::Source->value => 'apod', self::UNLISTED_KEY => self::UNLISTED_VALUE]);

        // Then
        $context = self::lastRecordContext();
        self::assertSame('apod', $context[LogContextKey::Source->value]);
        self::assertSame(AllowedLogContextProcessor::MASK, $context[self::UNLISTED_KEY]);
    }

    #[DataProvider('ownChannels')]
    public function testMasksAnUnlistedKeyUsedInTheMessageTemplate(string $logger): void
    {
        // Given
        $channel = self::getContainer()->get($logger);
        self::assertInstanceOf(LoggerInterface::class, $channel);

        // When
        $channel->warning('Rejected {'.self::UNLISTED_KEY.'}', [self::UNLISTED_KEY => self::UNLISTED_VALUE]);

        // Then
        $records = self::logHandler()->getRecords();
        self::assertNotEmpty($records);
        $formatted = end($records)->formatted;
        self::assertIsString($formatted);
        self::assertStringNotContainsString(self::UNLISTED_VALUE, $formatted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ownChannels(): iterable
    {
        yield 'app' => ['monolog.logger'];
        yield 'briefing' => ['monolog.logger.briefing'];
        yield 'identity' => ['monolog.logger.identity'];
    }

    public function testLeavesFrameworkChannelsToTheRedactingFormatter(): void
    {
        // Given
        $logger = self::getContainer()->get('monolog.logger.request');

        // When
        $logger->warning('Framework event', [self::UNLISTED_KEY => self::UNLISTED_VALUE]);

        // Then
        self::assertSame(self::UNLISTED_VALUE, self::lastRecordContext()[self::UNLISTED_KEY]);
    }

    /**
     * @return array<mixed>
     */
    private static function lastRecordContext(): array
    {
        $records = self::logHandler()->getRecords();
        self::assertNotEmpty($records);

        return end($records)->context;
    }
}
