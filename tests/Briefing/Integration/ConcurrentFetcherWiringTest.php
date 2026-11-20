<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\Model\SourcePayload;
use App\Briefing\Source\ConcurrentFetcher;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\SettledFetch;
use App\Briefing\Source\SourceFetch;
use App\Briefing\SourceName;
use App\Briefing\SourceStatus;
use App\Tests\Support\Briefing\FakePayload;
use App\Tests\Support\ExposingKernel;
use App\Tests\Support\Http\DelayedUpstream;
use App\Tests\Support\InspectedContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ConcurrentFetcherWiringTest extends TestCase
{
    private const array CLIENTS = ['nasa.client', 'wikipedia.client', 'open_meteo.client', 'open_meteo_archive.client'];
    private const array BASE_URIS = ['NASA_BASE_URI', 'WIKIPEDIA_BASE_URI', 'OPEN_METEO_BASE_URI', 'OPEN_METEO_ARCHIVE_BASE_URI'];
    private const string REFUSING_HOST = 'http://127.0.0.1:9/';
    private const string LOG_LEVEL = 'LOG_LEVEL';
    private const string ONLY_ERRORS = 'error';
    private const string PATH = 'resource';
    private const string FAILING_ONCE_AFTER_A_PAUSE = 'resource?key=%s&fail=1&sleep=0.3';
    private const float NANOSECONDS_PER_SECOND = 1e9;
    private const string FETCH_CLOCK = 'app.briefing.fetch_clock';

    /**
     * @var array<string, mixed>
     */
    private array $originalEnv = [];

    private ?DelayedUpstream $upstream = null;

    protected function setUp(): void
    {
        $this->override(self::LOG_LEVEL, self::ONLY_ERRORS);
    }

    protected function tearDown(): void
    {
        $this->upstream?->stop();

        foreach ($this->originalEnv as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    #[DataProvider('environments')]
    public function testStreamsEveryUpstreamTogetherThroughTheRealClients(string $environment): void
    {
        // Given
        $this->pointUpstreamsAt(self::REFUSING_HOST);
        $container = ExposingKernel::booted($environment, [...self::CLIENTS, ConcurrentFetcher::class]);
        $fetches = array_map(static fn (string $client): SourceFetch => self::fetchFrom(self::service($container, $client), self::PATH), self::CLIENTS);

        // When
        $settled = self::fetcherIn($container)->settleAll($fetches);

        // Then
        self::assertSame(array_fill(0, \count(self::CLIENTS), 'TransportException'), array_map(static fn (SettledFetch $fetch): ?string => $fetch->result->failure?->cause?->errorType, $settled));
    }

    #[DataProvider('environments')]
    public function testRetriesEveryUpstreamAtTheSameTime(string $environment): void
    {
        // Given
        $this->upstream = DelayedUpstream::start();
        $this->pointUpstreamsAt($this->upstream->baseUri);
        $container = ExposingKernel::booted($environment, [...self::CLIENTS, ConcurrentFetcher::class]);
        $startedAt = hrtime(true);
        $fetches = array_map(static fn (string $client): SourceFetch => self::fetchFrom(self::service($container, $client), \sprintf(self::FAILING_ONCE_AFTER_A_PAUSE, uniqid($environment.$client, true))), self::CLIENTS);

        // When
        $settled = self::fetcherIn($container)->settleAll($fetches);

        // Then
        $seconds = (hrtime(true) - $startedAt) / self::NANOSECONDS_PER_SECOND;
        self::assertSame(array_fill(0, \count(self::CLIENTS), SourceStatus::Ok), array_map(static fn (SettledFetch $fetch): SourceStatus => $fetch->result->status, $settled));
        self::assertGreaterThan(0.8, $seconds);
        self::assertLessThan(1.6, $seconds);
    }

    #[DataProvider('environments')]
    public function testMeasuresTheDeadlineOnAMonotonicClock(string $environment): void
    {
        // When
        $clock = InspectedContainer::of($environment)->findDefinition(self::FETCH_CLOCK)->getClass();

        // Then
        self::assertSame(MonotonicClock::class, $clock);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'prod' => ['prod'];
    }

    private function pointUpstreamsAt(string $baseUri): void
    {
        foreach (self::BASE_URIS as $name) {
            $this->override($name, $baseUri);
        }
    }

    private function override(string $name, string $value): void
    {
        $this->originalEnv[$name] ??= $_SERVER[$name] ?? null;
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    private static function fetcherIn(ContainerInterface $container): ConcurrentFetcher
    {
        $fetcher = self::service($container, ConcurrentFetcher::class);
        self::assertInstanceOf(ConcurrentFetcher::class, $fetcher);

        return $fetcher;
    }

    private static function service(ContainerInterface $container, string $id): object
    {
        return $container->get($id);
    }

    private static function fetchFrom(object $client, string $path): SourceFetch
    {
        self::assertInstanceOf(HttpClientInterface::class, $client);
        $response = $client->request(Request::METHOD_GET, $path);

        return new SourceFetch(SourceName::Apod, new PendingFetch([$response], static fn (): SourcePayload => new FakePayload([]), new NativeClock()));
    }
}
