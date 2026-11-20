<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Repository;

use App\Tests\Support\ExposingKernel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaConstraintsTest extends TestCase
{
    private const string TABLE = 'briefing_source_snapshot';
    private const string CHECK_VIOLATION = '23514';
    private const array VALID_SNAPSHOT = [
        'source' => 'weather',
        'scope_key' => '2024-03-15@52.23,21.01',
        'date' => '2024-03-15',
        'status' => 'ok',
        'error' => null,
        'payload' => '{"kind": "final"}',
        'fetched_at' => '2026-11-20 10:00:00+00',
        'last_attempt_at' => '2026-11-20 10:00:00+00',
    ];

    public function testAcceptsAValidSnapshot(): void
    {
        // When
        $inserted = self::connection()->insert(self::TABLE, self::VALID_SNAPSHOT);

        // Then
        self::assertSame(1, (int) $inserted);
    }

    /**
     * @param array<string, string|null> $change
     */
    #[DataProvider('invalidSnapshots')]
    public function testRejectsASnapshotThatBreaksAConstraint(array $change, string $constraint): void
    {
        // When
        $failure = self::failureToInsert($change + self::VALID_SNAPSHOT);

        // Then
        self::assertSame(self::CHECK_VIOLATION, $failure->getSQLState());
        self::assertStringContainsString("\"{$constraint}\"", $failure->getMessage());
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function invalidSnapshots(): iterable
    {
        yield 'unknown source' => [['source' => 'tides'], 'briefing_source_snapshot_source_check'];
        yield 'empty scope key' => [['scope_key' => ''], 'briefing_source_snapshot_scope_key_check'];
        yield 'dated source without a date' => [['date' => null], 'briefing_source_snapshot_date_check'];
        yield 'day in history with a date' => [['source' => 'on_this_day', 'scope_key' => '03-15'], 'briefing_source_snapshot_date_check'];
        yield 'status of a result that is never stored' => [['status' => 'not_applicable'], 'briefing_source_snapshot_status_check'];
        yield 'unknown error' => [['status' => 'failed', 'error' => 'exploded'], 'briefing_source_snapshot_error_check'];
        yield 'failure without an error' => [['status' => 'failed'], 'briefing_source_snapshot_failure_check'];
        yield 'success with an error' => [['error' => 'unavailable'], 'briefing_source_snapshot_failure_check'];
        yield 'success without data' => [['payload' => null, 'fetched_at' => null], 'briefing_source_snapshot_ok_payload_check'];
        yield 'data without the time it came' => [['fetched_at' => null], 'briefing_source_snapshot_fetched_check'];
        yield 'time it came without data' => [['status' => 'failed', 'error' => 'unavailable', 'payload' => null], 'briefing_source_snapshot_fetched_check'];
        yield 'data that is not an object' => [['payload' => '"just text"'], 'briefing_source_snapshot_payload_check'];
        yield 'data that is a list' => [['payload' => '[]'], 'briefing_source_snapshot_payload_check'];
    }

    /**
     * @param array<string, string|null> $snapshot
     */
    private static function failureToInsert(array $snapshot): DriverException
    {
        try {
            self::connection()->insert(self::TABLE, $snapshot);
        } catch (DriverException $failure) {
            return $failure;
        }

        self::fail('The snapshot was stored although it breaks a constraint.');
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
