<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Repository;

use App\Tests\Support\ExposingKernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscoveryConstraintsTest extends TestCase
{
    private const string TABLE = 'briefing_discovery';
    private const string CHECK_VIOLATION = '23514';
    private const array VALID_DISCOVERY = [
        'id' => 'c05f9b6a-9262-5859-b45d-743a62e1bc6e',
        'key' => 'apod:2024-03-15',
        'type' => 'apod',
        'date' => '2024-03-15',
        'title' => 'Portrait of NGC 1055',
        'data' => '{"summary": "A galaxy.", "url": null}',
        'created_at' => '2026-11-20 10:00:00+00',
    ];

    public function testAcceptsAValidDiscovery(): void
    {
        // When
        $inserted = self::connection()->insert(self::TABLE, self::VALID_DISCOVERY);

        // Then
        self::assertSame(1, (int) $inserted);
    }

    /**
     * @param array<string, string|null> $change
     */
    #[DataProvider('invalidDiscoveries')]
    public function testRejectsADiscoveryThatBreaksAConstraint(array $change, string $constraint): void
    {
        // When
        $failure = self::failureToInsert($change + self::VALID_DISCOVERY);

        // Then
        self::assertSame(self::CHECK_VIOLATION, $failure->getSQLState());
        self::assertStringContainsString("\"{$constraint}\"", $failure->getMessage());
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function invalidDiscoveries(): iterable
    {
        yield 'id of another version' => [['id' => '7b4f5f0e-1d1a-4c8e-9a6b-2f6d3c5e8a90'], 'briefing_discovery_id_check'];
        yield 'unknown type' => [['type' => 'planet', 'key' => 'planet:mars'], 'briefing_discovery_type_check'];
        yield 'key of another type' => [['key' => 'event:2024-03-15'], 'briefing_discovery_key_check'];
        yield 'picture without a date' => [['date' => null], 'briefing_discovery_date_check'];
        yield 'empty title' => [['title' => ''], 'briefing_discovery_title_check'];
        yield 'data that is not an object' => [['data' => '[]'], 'briefing_discovery_data_check'];
    }

    /**
     * @param array<string, string|null> $discovery
     */
    private static function failureToInsert(array $discovery): DriverException
    {
        try {
            self::connection()->insert(self::TABLE, $discovery);
        } catch (DriverException $failure) {
            return $failure;
        }

        self::fail('The discovery was stored although it breaks a constraint.');
    }

    private static function connection(): Connection
    {
        $connection = self::service(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private static function service(string $id): object
    {
        return ExposingKernel::booted('test', [Connection::class])->get($id);
    }
}
