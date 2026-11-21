<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Metrics;

use App\Shared\Metrics\Metric;
use App\Shared\Metrics\PrometheusMetrics;
use App\Tests\Support\Metrics\FailingMetricsStorage;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prometheus\Exception\StorageException;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Clock\MockClock;

final class PrometheusMetricsTest extends TestCase
{
    private const string NOW = '2026-11-21 10:00:00';

    public function testCountsAMetricWithItsLabels(): void
    {
        // Given
        $metrics = self::metrics(new InMemory());

        // When
        $metrics->increment(Metric::UpstreamFetches, ['source' => 'apod', 'result' => 'ok']);
        $metrics->increment(Metric::UpstreamFetches, ['source' => 'apod', 'result' => 'ok']);

        // Then
        self::assertStringContainsString("almanac_upstream_fetches_total{source=\"apod\",result=\"ok\"} 2\n", $metrics->render());
    }

    public function testObservesADurationInTheBucketsOfItsMetric(): void
    {
        // Given
        $metrics = self::metrics(new InMemory());

        // When
        $metrics->observe(Metric::UpstreamFetchDuration, 1.2, ['source' => 'weather']);

        // Then
        $text = $metrics->render();
        self::assertStringContainsString("almanac_upstream_fetch_duration_seconds_bucket{source=\"weather\",le=\"1\"} 0\n", $text);
        self::assertStringContainsString("almanac_upstream_fetch_duration_seconds_bucket{source=\"weather\",le=\"1.5\"} 1\n", $text);
        self::assertStringContainsString("almanac_upstream_fetch_duration_seconds_bucket{source=\"weather\",le=\"3.5\"} 1\n", $text);
    }

    public function testRefusesLabelsThatTheMetricDoesNotDefine(): void
    {
        // Given
        $metrics = self::metrics(new InMemory());

        // Then / When
        $this->expectExceptionObject(new \InvalidArgumentException('upstream_fetches_total takes the labels source, result, in that order.'));
        $metrics->increment(Metric::UpstreamFetches, ['source' => 'apod', 'date' => '2024-03-15']);
    }

    public function testDropsTheMetricAndLogsOnceWhenTheStoreFails(): void
    {
        // Given
        $storage = new FailingMetricsStorage();
        $logs = new TestHandler();
        $metrics = new PrometheusMetrics($storage, new MockClock(self::NOW), new Logger('app', [$logs]));

        // When
        $metrics->increment(Metric::CircuitTransitions, ['source' => 'apod', 'to' => 'open']);
        $metrics->increment(Metric::CircuitTransitions, ['source' => 'apod', 'to' => 'closed']);

        // Then
        self::assertSame(1, $storage->writes);
        self::assertCount(1, $logs->getRecords());
        self::assertSame(Level::Warning, $logs->getRecords()[0]->level);
        self::assertSame('circuit_transitions_total', $logs->getRecords()[0]->context['metric']);
    }

    /**
     * @param \Closure(PrometheusMetrics): void $write
     */
    #[DataProvider('failedWrites')]
    public function testNeverLetsAFailedStoreReachTheCaller(\Throwable $failure, \Closure $write): void
    {
        // Given
        $storage = new FailingMetricsStorage($failure);
        $metrics = new PrometheusMetrics($storage, new MockClock(self::NOW), new NullLogger());

        // When
        $write($metrics);

        // Then
        self::assertSame(1, $storage->writes);
    }

    /**
     * @return iterable<string, array{\Throwable, \Closure(PrometheusMetrics): void}>
     */
    public static function failedWrites(): iterable
    {
        $observe = static fn (PrometheusMetrics $metrics) => $metrics->observe(Metric::UpstreamFetchDuration, 0.2, ['source' => 'apod']);

        yield 'a lazy connection that cannot connect' => [new InvalidArgumentException('Redis connection failed: Connection refused'), $observe];
        yield 'a command that fails' => [new \RedisException('read error on connection'), $observe];
        yield 'a store that is down' => [new StorageException('Redis is down'), $observe];
    }

    public function testTriesTheStoreAgainAfterAPause(): void
    {
        // Given
        $storage = new FailingMetricsStorage();
        $clock = new MockClock(self::NOW);
        $metrics = new PrometheusMetrics($storage, $clock, new NullLogger());
        $metrics->increment(Metric::CircuitTransitions, ['source' => 'apod', 'to' => 'open']);

        // When
        $clock->sleep(30);
        $metrics->increment(Metric::CircuitTransitions, ['source' => 'apod', 'to' => 'open']);

        // Then
        self::assertSame(2, $storage->writes);
    }

    private static function metrics(Adapter $storage): PrometheusMetrics
    {
        return new PrometheusMetrics($storage, new MockClock(self::NOW), new NullLogger());
    }
}
