<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Tests\Support\Briefing\BriefingRateLimit;
use App\Tests\Support\ExposingKernel;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class RateLimiterRedisTest extends TestCase
{
    private const string REDIS_URL = 'REDIS_URL';
    private const string REFUSING_REDIS = 'redis://127.0.0.1:9?timeout=0.2&read_timeout=0.2';
    private const string LOG_LEVEL = 'LOG_LEVEL';
    private const string ONLY_ERRORS = 'error';
    private const int REQUESTS_OVER_THE_LIMIT = BriefingRateLimit::LIMIT + 1;
    private const string CACHE_LOGGER = 'monolog.logger.cache';
    private const string REDIS_CONNECTION_FAILED = 'Redis connection failed';

    /**
     * @var array<string, mixed>
     */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->override(self::LOG_LEVEL, self::ONLY_ERRORS);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    #[DataProvider('environments')]
    public function testKeepsTheCountInRedisAcrossProcesses(string $environment): void
    {
        // Given
        $client = uniqid($environment, true);
        self::acceptedOf(self::briefingLimiterIn(self::booted($environment))->create($client), BriefingRateLimit::LIMIT);

        // When
        $accepted = self::briefingLimiterIn(self::booted($environment))->create($client)->consume()->isAccepted();

        // Then
        self::assertFalse($accepted);
    }

    #[DataProvider('environments')]
    public function testLetsEveryRequestThroughWhileRedisIsDown(string $environment): void
    {
        // Given
        $this->override(self::REDIS_URL, self::REFUSING_REDIS);
        $container = self::booted($environment);
        $cacheLog = self::cacheLogIn($container);
        $limiter = self::briefingLimiterIn($container)->create(uniqid($environment, true));

        // When
        $accepted = self::acceptedOf($limiter, self::REQUESTS_OVER_THE_LIMIT);

        // Then
        self::assertSame(array_fill(0, self::REQUESTS_OVER_THE_LIMIT, true), $accepted);
        self::assertTrue($cacheLog->hasWarningThatContains(self::REDIS_CONNECTION_FAILED));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'prod' => ['prod'];
    }

    private static function booted(string $environment): ContainerInterface
    {
        return ExposingKernel::booted($environment, [BriefingRateLimit::LIMITER, self::CACHE_LOGGER]);
    }

    private static function briefingLimiterIn(ContainerInterface $container): RateLimiterFactoryInterface
    {
        $limiter = self::service($container, BriefingRateLimit::LIMITER);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);

        return $limiter;
    }

    private static function cacheLogIn(ContainerInterface $container): TestHandler
    {
        $logger = self::service($container, self::CACHE_LOGGER);
        self::assertInstanceOf(Logger::class, $logger);
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        return $handler;
    }

    private static function service(ContainerInterface $container, string $id): object
    {
        return $container->get($id);
    }

    /**
     * @return list<bool>
     */
    private static function acceptedOf(LimiterInterface $limiter, int $requests): array
    {
        return array_map(static fn (): bool => $limiter->consume()->isAccepted(), range(1, $requests));
    }

    private function override(string $name, string $value): void
    {
        $this->originalEnv[$name] ??= $_SERVER[$name] ?? null;
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
