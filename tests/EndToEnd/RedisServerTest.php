<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\EndToEnd\HttpProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class RedisServerTest extends TestCase
{
    private const string URL_VARIABLE = 'REDIS_URL';
    private const string HOST = 'host';
    private const string PORT = 'port';
    private const int DEFAULT_PORT = 6379;
    private const int MAX_MEMORY_BYTES = 64 * 1024 * 1024;
    private const int REQUESTS = 20;
    private const string RATE_LIMITED_PATH = '/api/briefings/2024-03-15?lat=999&lon=0';
    private const string STATS = 'stats';
    private const string CONNECTIONS_RECEIVED = 'total_connections_received';
    private const string COMMAND_STATS = 'commandstats';
    private const string CALLS = '/calls=(\d+)/';
    private const array EXPECTED_STATUSES = [Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_TOO_MANY_REQUESTS];

    public function testKeepsDataOnlyInMemoryWithinALimit(): void
    {
        // Given
        $redis = self::connected();

        // When
        $config = $redis->config('GET', '*');

        // Then
        self::assertIsArray($config);
        self::assertSame('', $config['save']);
        self::assertSame('no', $config['appendonly']);
        self::assertSame((string) self::MAX_MEMORY_BYTES, $config['maxmemory']);
        self::assertSame('volatile-lru', $config['maxmemory-policy']);
    }

    public function testReusesConnectionsAcrossRequests(): void
    {
        // Given
        $redis = self::connected();
        $connectionsBefore = self::connectionsReceived($redis);
        $commandsBefore = self::commandsProcessed($redis);

        // When
        $statuses = array_map(static fn (): int => HttpProbe::fromEnvironment()->get(self::RATE_LIMITED_PATH)->status, range(1, self::REQUESTS));

        // Then
        self::assertSame([], array_diff($statuses, self::EXPECTED_STATUSES));
        self::assertGreaterThanOrEqual(self::REQUESTS, self::commandsProcessed($redis) - $commandsBefore);
        self::assertLessThan(self::REQUESTS, self::connectionsReceived($redis) - $connectionsBefore);
    }

    private static function commandsProcessed(\Redis $redis): int
    {
        $stats = $redis->info(self::COMMAND_STATS);
        self::assertIsArray($stats);

        return array_sum(array_map(self::callsOf(...), $stats));
    }

    private static function callsOf(mixed $commandStats): int
    {
        self::assertIsString($commandStats);
        self::assertSame(1, preg_match(self::CALLS, $commandStats, $matches));

        return (int) $matches[1];
    }

    private static function connectionsReceived(\Redis $redis): int
    {
        $stats = $redis->info(self::STATS);
        self::assertIsArray($stats);

        $received = $stats[self::CONNECTIONS_RECEIVED];
        self::assertIsNumeric($received);

        return (int) $received;
    }

    private static function connected(): \Redis
    {
        [$host, $port] = self::hostAndPort();
        $redis = new \Redis();
        $redis->connect($host, $port);

        return $redis;
    }

    /**
     * @return array{string, int}
     */
    private static function hostAndPort(): array
    {
        $url = getenv(self::URL_VARIABLE);
        self::assertIsString($url);
        $parts = parse_url($url);
        self::assertIsArray($parts);
        self::assertIsString($parts[self::HOST] ?? null);

        return [$parts[self::HOST], $parts[self::PORT] ?? self::DEFAULT_PORT];
    }
}
