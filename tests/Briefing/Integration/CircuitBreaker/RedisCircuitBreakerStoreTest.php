<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\CircuitBreaker;

use App\Briefing\CircuitBreaker\CircuitBreakerStore;
use App\Briefing\CircuitBreaker\RedisCircuitBreakerStore;
use App\Briefing\Elapsed;
use App\Tests\Support\Briefing\CircuitBreaker\CircuitBreakerStoreContract;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Clock\MockClock;

final class RedisCircuitBreakerStoreTest extends CircuitBreakerStoreContract
{
    private const string NOW = '2026-11-18 12:00:00.250 Europe/Warsaw';
    private const string REDIS_URL = 'REDIS_URL';
    private const string REFUSING_REDIS = 'redis://127.0.0.1:1?timeout=0.2&read_timeout=0.2';
    private const string CIRCUIT = 'apod';
    private const string CHANNEL = 'briefing';
    private const int CALLS = 4;
    private const string EVERY_KEY = '*';
    private const int NO_EXPIRY = -1;

    private \Redis $redis;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->redis = self::connectionTo(self::testRedisUrl());
        $this->redis->flushDB();
    }

    protected function store(): CircuitBreakerStore
    {
        return new RedisCircuitBreakerStore($this->redis, $this->clock, new NullLogger());
    }

    protected function clock(): ClockInterface
    {
        return $this->clock;
    }

    protected function elapse(int $milliseconds): void
    {
        $this->clock->sleep($milliseconds / Elapsed::MILLISECONDS_PER_SECOND);

        foreach ($this->redis->keys(self::EVERY_KEY) as $key) {
            $this->shortenTheTimeToLive($key, $milliseconds);
        }
    }

    private function shortenTheTimeToLive(string $key, int $milliseconds): void
    {
        $left = $this->redis->pttl($key);

        if (self::NO_EXPIRY === $left) {
            return;
        }

        if ($left > $milliseconds) {
            $this->redis->pexpire($key, $left - $milliseconds);

            return;
        }

        $this->redis->del($key);
    }

    public function testFailsOpenWithOneWarningAndNoFurtherCallsWhileRedisIsDown(): void
    {
        // Given
        $log = new TestHandler();
        $store = new RedisCircuitBreakerStore(self::connectionTo(self::REFUSING_REDIS), $this->clock, new Logger(self::CHANNEL, [$log]));

        // When
        $answers = [];
        for ($call = 0; $call < self::CALLS; ++$call) {
            $answers[] = [$store->openUntil(self::CIRCUIT), $store->recordFailure(self::CIRCUIT, $this->clock->now()->modify('+1 minute')), $store->claimTrial(self::CIRCUIT, $this->clock->now()->modify('+1 minute'))];
        }

        // Then
        self::assertSame(array_fill(0, self::CALLS, [null, 0, true]), $answers);
        self::assertCount(1, $log->getRecords());
        self::assertSame(Level::Warning, $log->getRecords()[0]->level);
        self::assertSame('openUntil', $log->getRecords()[0]->context['operation'] ?? null);
    }

    public function testReportsNoOpeningWhileRedisIsDown(): void
    {
        // Given
        $log = new TestHandler();
        $store = new RedisCircuitBreakerStore(self::connectionTo(self::REFUSING_REDIS), $this->clock, new Logger(self::CHANNEL, [$log]));

        // When
        $opened = $store->open(self::CIRCUIT, $this->clock->now()->modify('+1 minute'));

        // Then
        self::assertFalse($opened);
        self::assertCount(1, $log->getRecords());
        self::assertSame('open', $log->getRecords()[0]->context['operation'] ?? null);
    }

    public function testFailsOpenWhenRedisAnswersWithAnError(): void
    {
        // Given
        $log = new TestHandler();
        $store = new RedisCircuitBreakerStore($this->redis, $this->clock, new Logger(self::CHANNEL, [$log]));
        $this->redis->hSet('briefing:cb:{apod}:open_until', 'field', 'value');

        // When
        $openUntil = $store->openUntil(self::CIRCUIT);
        $claimed = $store->claimTrial(self::CIRCUIT, $this->clock->now()->modify('+30 seconds'));

        // Then
        self::assertNull($openUntil);
        self::assertTrue($claimed);
        self::assertCount(1, $log->getRecords());
        self::assertSame(0, $this->redis->exists('briefing:cb:{apod}:trial'));
    }

    public function testAsksRedisAgainThirtySecondsAfterAFailure(): void
    {
        // Given
        $log = new TestHandler();
        $store = new RedisCircuitBreakerStore(self::connectionTo(self::REFUSING_REDIS), $this->clock, new Logger(self::CHANNEL, [$log]));
        $store->openUntil(self::CIRCUIT);
        $this->clock->sleep(29.9);
        $store->openUntil(self::CIRCUIT);

        // When
        $this->clock->sleep(0.1);
        $store->openUntil(self::CIRCUIT);

        // Then
        self::assertCount(2, $log->getRecords());
    }

    public function testAsksRedisAgainAfterReset(): void
    {
        // Given
        $log = new TestHandler();
        $store = new RedisCircuitBreakerStore(self::connectionTo(self::REFUSING_REDIS), $this->clock, new Logger(self::CHANNEL, [$log]));
        $store->openUntil(self::CIRCUIT);

        // When
        $store->reset();
        $store->openUntil(self::CIRCUIT);

        // Then
        self::assertCount(2, $log->getRecords());
    }

    public function testLetsRedisForgetEveryKeyButTheOpening(): void
    {
        // Given
        $store = $this->store();

        // When
        $store->open(self::CIRCUIT, $this->clock->now()->modify('-1 minute'));
        $store->recordFailure(self::CIRCUIT, $this->clock->now()->modify('+10 minutes'));
        $store->claimTrial(self::CIRCUIT, $this->clock->now()->modify('+30 seconds'));

        // Then
        self::assertEqualsWithDelta(600_000, $this->redis->pttl('briefing:cb:{apod}:failures'), 1000);
        self::assertEqualsWithDelta(30_000, $this->redis->pttl('briefing:cb:{apod}:trial'), 1000);
        self::assertSame(-1, $this->redis->pttl('briefing:cb:{apod}:open_until'));
    }

    private static function testRedisUrl(): string
    {
        $url = $_SERVER[self::REDIS_URL] ?? $_ENV[self::REDIS_URL] ?? null;

        if (\is_string($url)) {
            return $url;
        }

        throw new \LogicException('The test environment sets REDIS_URL.');
    }

    private static function connectionTo(string $url): \Redis
    {
        $connection = RedisAdapter::createConnection($url, ['lazy' => true]);

        if ($connection instanceof \Redis) {
            return $connection;
        }

        throw new \LogicException('A redis:// URL gives a \Redis connection.');
    }
}
