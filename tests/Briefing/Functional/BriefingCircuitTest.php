<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Functional;

use App\Briefing\CircuitBreaker\CircuitBreakerStore;
use App\Briefing\SourceName;
use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\InteractsWithTestServices;
use Doctrine\DBAL\Connection;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BriefingCircuitTest extends FunctionalTestCase
{
    use InteractsWithTestServices;

    private const string BRIEFING = '/api/briefings/';
    private const string BRIEFING_CHANNEL = 'briefing';
    private const string PICTURE_DAY = '2024-03-15';
    private const string NEXT_PICTURE_DAY = '2024-03-16';
    private const string PICTURE = 'apod/passthrough-2024-03-15.json';
    private const string HISTORY_DAY = '03-15';
    private const string FORECAST_DAY = '2026-11-25';
    private const string NASA_HOST = 'api.nasa.gov';
    private const string CONTENT_TYPE = 'Content-Type';
    private const array NOT_APPLICABLE = ['status' => 'not_applicable'];
    private const array PLACES = ['?lat=52.23&lon=21.01', '?lat=50.06&lon=19.94', '?lat=51.11&lon=17.03', '?lat=54.35&lon=18.65', '?lat=53.43&lon=14.55', '?lat=51.76&lon=19.46'];
    private const string WEATHER_FAILED = 'Source weather fetch failed: unavailable';

    /**
     * @var list<array{0: Level, 1: string, 2?: array<string, mixed>}>
     */
    private array $log = [];

    public function testServesTheLastGoodWeatherAsStaleWithoutAskingOpenMeteoOnceThreeOutagesOpenedItsCircuit(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->onThisDayFrom(self::HISTORY_DAY)->forecastOn(self::FORECAST_DAY));
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::FORECAST_DAY);
        $weather = self::json($this->client->getResponse()->getContent())['weather'];
        self::at('13:00:00');
        self::upstream()->respondWith(self::threeWeatherOutages());
        $this->requestForecastIn(0, 1, 2);
        self::upstream()->respondWith(UpstreamMock::create());

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::FORECAST_DAY);

        // Then
        self::assertResponseIsSuccessful();
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(['apod' => self::NOT_APPLICABLE, 'onThisDay' => self::fresh('12:00:00'), 'weather' => ['status' => 'ok', 'fetchedAt' => '2026-11-18T12:00:00Z', 'stale' => true, 'error' => 'unavailable', 'lastAttemptAt' => '2026-11-18T13:00:00Z']], $briefing['sources']);
        self::assertSame(['apod'], $briefing['missing']);
        self::assertSame($weather, $briefing['weather']);
        self::assertSame([], self::upstream()->served());
    }

    public function testLogsTheWholeCycleOfACircuitThatClosesAfterASuccessfulTrial(): void
    {
        // Given
        self::upstream()->respondWith(self::threeWeatherOutages());
        $this->requestForecastIn(0, 1, 2);
        self::at('12:00:30');
        self::upstream()->respondWith(UpstreamMock::create());
        $this->requestForecastIn(3);
        $refused = $this->sources();
        $refusedRequests = self::upstream()->served();
        self::at('12:01:00');
        self::upstream()->respondWith(UpstreamMock::create()->forecastOn(self::FORECAST_DAY)->forecastOn(self::FORECAST_DAY));

        // When
        $this->requestForecastIn(4);
        $trial = $this->sources();
        $this->requestForecastIn(5);

        // Then
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'failed', 'error' => 'unavailable', 'lastAttemptAt' => '2026-11-18T12:00:30Z'], $refused['weather'] ?? null);
        self::assertSame([], $refusedRequests);
        self::assertSame(self::fresh('12:01:00'), $trial['weather'] ?? null);
        self::assertSame(self::fresh('12:01:00'), $this->sources()['weather'] ?? null);
        self::assertSame([], self::upstream()->unserved());
        self::assertSame([
            [Level::Warning, self::WEATHER_FAILED],
            [Level::Warning, self::WEATHER_FAILED],
            [Level::Warning, 'Circuit weather opened for 60s', ['source' => 'weather', 'kind' => 'unavailable', 'failures' => 3, 'openSeconds' => 60, 'trial' => false]],
            [Level::Warning, self::WEATHER_FAILED],
            [Level::Info, 'Circuit weather half-open, trial allowed', ['source' => 'weather']],
            [Level::Info, 'Circuit weather closed after its trial', ['source' => 'weather']],
        ], $this->log);
    }

    public function testReopensTheCircuitWhenItsTrialFailsAndRefusesTheNextRequest(): void
    {
        // Given
        self::upstream()->respondWith(self::threeWeatherOutages());
        $this->requestForecastIn(0, 1, 2);
        self::at('12:01:00');
        self::upstream()->respondWith(UpstreamMock::create()->forecast()->status(Response::HTTP_SERVICE_UNAVAILABLE)->forecast()->status(Response::HTTP_SERVICE_UNAVAILABLE));

        // When
        $this->requestForecastIn(3, 4);

        // Then
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'failed', 'error' => 'unavailable', 'lastAttemptAt' => '2026-11-18T12:01:00Z'], $this->sources()['weather'] ?? null);
        self::assertCount(2, self::upstream()->served());
        self::assertSame([
            [Level::Info, 'Circuit weather half-open, trial allowed', ['source' => 'weather']],
            [Level::Warning, 'Circuit weather opened for 60s', ['source' => 'weather', 'kind' => 'unavailable', 'openSeconds' => 60, 'trial' => true]],
            [Level::Warning, self::WEATHER_FAILED],
        ], \array_slice($this->log, 4));
    }

    public function testRefusesNasaUntilTheRetryAfterOfItsRateLimitAndTriesAgainThen(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->onThisDayFrom(self::HISTORY_DAY)->archiveOn(self::PICTURE_DAY)->apod()->status(Response::HTTP_TOO_MANY_REQUESTS)->retryAfter(3600));
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);
        self::at('12:59:59');
        self::upstream()->respondWith(UpstreamMock::create()->onThisDayFrom(self::HISTORY_DAY)->archiveOn(self::NEXT_PICTURE_DAY));
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::NEXT_PICTURE_DAY);
        $refused = $this->sources();
        $refusedMissing = self::json($this->client->getResponse()->getContent())['missing'];
        $refusedRequests = self::requestedHosts(self::NASA_HOST);
        self::at('13:00:00');
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture(self::PICTURE));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'failed', 'error' => 'unavailable', 'lastAttemptAt' => '2026-11-18T12:59:59Z'], $refused['apod'] ?? null);
        self::assertSame(['apod'], $refusedMissing);
        self::assertSame([], $refusedRequests);
        self::assertSame(['apod' => self::fresh('13:00:00'), 'onThisDay' => self::fresh('12:00:00'), 'weather' => self::fresh('12:00:00')], $this->sources());
        self::assertSame([], self::upstream()->unserved());
    }

    public function testAnswersBadGatewayWithoutCallingOrStoringAnythingWhenEveryCircuitIsOpen(): void
    {
        // Given
        foreach (SourceName::cases() as $source) {
            self::testService(CircuitBreakerStore::class)->open($source->value, new \DateTimeImmutable('2026-11-18 12:01:00', new \DateTimeZone(self::TIMEZONE)));
        }
        self::upstream()->respondWith(UpstreamMock::create());

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        self::assertResponseHeaderSame(self::CONTENT_TYPE, ProblemJsonErrorRenderer::PROBLEM_JSON);
        self::assertSame([], self::upstream()->served());
        self::assertSame([], self::testService(Connection::class)->fetchFirstColumn('SELECT scope_key FROM briefing_source_snapshot'));
        self::assertSame([], self::briefingLog());
        self::assertSame([Level::Warning], self::loggedExceptionLevels());
    }

    protected function tearDown(): void
    {
        self::assertSame([], self::upstream()->unserved(), 'Every planned upstream response must be requested.');
        parent::tearDown();
    }

    private function requestForecastIn(int ...$places): void
    {
        foreach ($places as $place) {
            $this->client->request(Request::METHOD_GET, self::BRIEFING.self::FORECAST_DAY.self::PLACES[$place]);
            $this->log = [...$this->log, ...self::briefingLog()];
        }
    }

    private static function threeWeatherOutages(): UpstreamMock
    {
        return UpstreamMock::create()
            ->onThisDayFrom(self::HISTORY_DAY)
            ->forecast()->status(Response::HTTP_SERVICE_UNAVAILABLE)->forecast()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->forecast()->status(Response::HTTP_SERVICE_UNAVAILABLE)->forecast()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->forecast()->status(Response::HTTP_SERVICE_UNAVAILABLE)->forecast()->status(Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private static function at(string $time): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-18 '.$time, new \DateTimeZone(self::TIMEZONE)));
    }

    /**
     * @return array{status: string, fetchedAt: string, stale: false}
     */
    private static function fresh(string $time): array
    {
        return ['status' => 'ok', 'fetchedAt' => '2026-11-18T'.$time.'Z', 'stale' => false];
    }

    /**
     * @return array<mixed>
     */
    private function sources(): array
    {
        $sources = self::json($this->client->getResponse()->getContent())['sources'] ?? null;
        self::assertIsArray($sources);

        return $sources;
    }

    private static function upstream(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }

    /**
     * @return array<mixed>
     */
    private static function json(string|false $content): array
    {
        $decoded = json_decode((string) $content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<array{0: Level, 1: string, 2?: array<string, mixed>}>
     */
    private static function briefingLog(): array
    {
        return array_map(static fn (LogRecord $record): array => self::isCircuitRecord($record) ? [$record->level, $record->message, $record->context] : [$record->level, $record->message], self::recordsOf(self::BRIEFING_CHANNEL));
    }

    private static function isCircuitRecord(LogRecord $record): bool
    {
        return str_starts_with($record->message, 'Circuit ');
    }

    /**
     * @return list<string>
     */
    private static function requestedHosts(string $host): array
    {
        return array_values(array_filter(array_map(static fn (MockResponse $response): string => (string) parse_url($response->getRequestUrl(), \PHP_URL_HOST), self::upstream()->served()), static fn (string $requested): bool => $host === $requested));
    }
}
