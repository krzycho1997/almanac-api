<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Functional;

use App\Briefing\CircuitBreaker\CircuitBreaker;
use App\Briefing\CircuitBreaker\CircuitBreakerStore;
use App\Briefing\CircuitBreaker\CircuitPermit;
use App\Briefing\CircuitBreaker\RedisCircuitBreakerStore;
use App\Briefing\SourceName;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\InteractsWithTestServices;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\HttpFoundation\Request;

final class CircuitHealthTest extends FunctionalTestCase
{
    use InteractsWithTestServices;

    private const string HEALTH = '/api/health';
    private const string REFUSING_REDIS = 'redis://127.0.0.1:1?timeout=0.2&read_timeout=0.2';
    private const string CHANNEL = 'briefing';
    private const array ALL_CLOSED = ['apod' => 'closed', 'onThisDay' => 'closed', 'weather' => 'closed'];

    public function testReportsEveryCircuitClosedByDefault(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::HEALTH);

        // Then
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ok","checks":{"circuits":{"apod":"closed","onThisDay":"closed","weather":"closed"}}}', (string) $this->client->getResponse()->getContent());
    }

    public function testReportsAnOpenCircuitAndStaysUp(): void
    {
        // Given
        self::openCircuit('weather', '2026-11-18 12:01:00');

        // When
        $this->client->request(Request::METHOD_GET, self::HEALTH);

        // Then
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok', 'circuits' => ['apod' => 'closed', 'onThisDay' => 'closed', 'weather' => 'open']], $this->report());
    }

    public function testReportsAHalfOpenCircuitWithoutTakingItsTrial(): void
    {
        // Given
        self::openCircuit('apod', '2026-11-18 11:59:00');

        // When
        $this->client->request(Request::METHOD_GET, self::HEALTH);
        $this->client->request(Request::METHOD_GET, self::HEALTH);

        // Then
        self::assertSame(['status' => 'ok', 'circuits' => ['apod' => 'half_open', 'onThisDay' => 'closed', 'weather' => 'closed']], $this->report());
        self::assertSame(CircuitPermit::Trial, self::testService(CircuitBreaker::class)->tryAcquire(SourceName::Apod));
    }

    public function testReportsCircuitsClosedWithOneWarningWhileRedisRefusesConnections(): void
    {
        // Given
        $log = new TestHandler();
        self::getContainer()->set(CircuitBreakerStore::class, new RedisCircuitBreakerStore(self::refusingRedis(), self::testService(ClockInterface::class), new Logger(self::CHANNEL, [$log])));

        // When
        $this->client->request(Request::METHOD_GET, self::HEALTH);

        // Then
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok', 'circuits' => self::ALL_CLOSED], $this->report());
        self::assertSame([Level::Warning], array_map(static fn ($record): Level => $record->level, $log->getRecords()));
    }

    /**
     * @return array{status: mixed, circuits: mixed}
     */
    private function report(): array
    {
        $health = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($health);
        self::assertIsArray($health['checks'] ?? null);

        return ['status' => $health['status'] ?? null, 'circuits' => $health['checks']['circuits'] ?? null];
    }

    private static function openCircuit(string $circuit, string $until): void
    {
        self::testService(CircuitBreakerStore::class)->open($circuit, new \DateTimeImmutable($until, new \DateTimeZone(self::TIMEZONE)));
    }

    private static function refusingRedis(): \Redis
    {
        $connection = RedisAdapter::createConnection(self::REFUSING_REDIS, ['lazy' => true]);
        self::assertInstanceOf(\Redis::class, $connection);

        return $connection;
    }
}
