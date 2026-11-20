<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source;

use App\Briefing\FailureKind;
use App\Briefing\Model\SourcePayload;
use App\Briefing\Source\ConcurrentFetcher;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\SettledFetch;
use App\Briefing\Source\SourceFetch;
use App\Briefing\SourceName;
use App\Briefing\SourceStatus;
use App\Tests\Support\Briefing\FakePayload;
use App\Tests\Support\Briefing\ScriptedUpstream;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ConcurrentFetcherTest extends TestCase
{
    private const string NOW = '2026-11-20 12:00:00';
    private const float DEADLINE = 3.5;
    private const string URL = 'https://upstream.test/resource';
    private const string BODY = '{}';
    private const string DEADLINE_EXCEEDED = 'DeadlineExceeded';

    private MockClock $clock;
    private ScriptedUpstream $upstream;
    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->upstream = new ScriptedUpstream($this->clock);
        $this->logs = new TestHandler();
    }

    public function testSettlesEverySourceWithTheTimeItTookToFinish(): void
    {
        // Given
        $picture = self::response();
        $weather = self::response();
        $this->upstream
            ->after(0.2, $weather, new FirstChunk())
            ->after(0.1, $picture, new FirstChunk())
            ->after(0.0, $picture, new LastChunk())
            ->after(0.7, $weather, new LastChunk());

        // When
        $settled = $this->fetcher()->settleAll([self::fetch(SourceName::Apod, $picture), self::fetch(SourceName::Weather, $weather)]);

        // Then
        self::assertSame([SourceStatus::Ok, SourceStatus::Ok], array_map(static fn (SettledFetch $fetch): SourceStatus => $fetch->result->status, $settled));
        self::assertSame([300, 1000], array_map(static fn (SettledFetch $fetch): int => $fetch->durationMs, $settled));
    }

    public function testCancelsASourceThatMissesTheDeadline(): void
    {
        // Given
        $picture = self::response();
        $weather = self::response();
        $this->upstream
            ->after(0.3, $picture, new FirstChunk())
            ->after(0.0, $picture, new LastChunk())
            ->after(0.25, $weather, new FirstChunk());

        // When
        [$pictureFetch, $weatherFetch] = $this->fetcher()->settleAll([self::fetch(SourceName::Apod, $picture), self::fetch(SourceName::Weather, $weather)]);

        // Then
        self::assertSame(SourceStatus::Ok, $pictureFetch->result->status);
        self::assertSame(SourceName::Weather, $weatherFetch->result->source);
        self::assertSame(FailureKind::Unavailable, $weatherFetch->result->failure?->kind);
        self::assertSame(self::DEADLINE_EXCEEDED, $weatherFetch->result->failure->cause?->errorType);
        self::assertSame(3500, $weatherFetch->durationMs);
        self::assertTrue($weather->getInfo('canceled'));
    }

    public function testWaitsForEveryResponseOfASource(): void
    {
        // Given
        $selected = self::response();
        $births = self::response();
        $this->upstream
            ->after(0.1, $selected, new FirstChunk())
            ->after(0.0, $selected, new LastChunk())
            ->after(0.1, $births, new FirstChunk());

        // When
        [$settled] = $this->fetcher()->settleAll([self::fetch(SourceName::OnThisDay, $selected, $births)]);

        // Then
        self::assertSame(self::DEADLINE_EXCEEDED, $settled->result->failure?->cause?->errorType);
        self::assertTrue($births->getInfo('canceled'));
    }

    public function testSettlesASourceOnItsFirstFailedResponseWithoutReadingTheOthers(): void
    {
        // Given
        $selected = self::response(new MockResponse(self::BODY, ['error' => 'Still in flight']));
        $births = self::response(new MockResponse(self::BODY, ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]));
        $this->upstream->after(0.2, $births, new FirstChunk());

        // When
        [$settled] = $this->fetcher()->settleAll([self::fetch(SourceName::OnThisDay, $selected, $births)]);

        // Then
        self::assertSame(FailureKind::Unavailable, $settled->result->failure?->kind);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $settled->result->failure->httpStatus);
        self::assertSame(200, $settled->durationMs);
        self::assertTrue($selected->getInfo('canceled'));
    }

    public function testTakesTheTimeOfTheFirstFailureWhenSeveralResponsesFail(): void
    {
        // Given
        $births = self::response(new MockResponse(self::BODY, ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]));
        $deaths = self::response();
        $this->upstream
            ->after(0.2, $births, new FirstChunk())
            ->after(0.05, $deaths, new ErrorChunk(0, new TransportException('Connection reset')));

        // When
        [$settled] = $this->fetcher()->settleAll([self::fetch(SourceName::OnThisDay, $births, $deaths)]);

        // Then
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $settled->result->failure?->httpStatus);
        self::assertSame(200, $settled->durationMs);
    }

    public function testStopsStreamingASourceAtItsFirstTransportError(): void
    {
        // Given
        $selected = self::response();
        $births = self::response(new MockResponse(self::BODY, ['error' => 'Connection reset']));
        $this->upstream->after(0.2, $births, new ErrorChunk(0, new TransportException('Connection reset')));

        // When
        [$settled] = $this->fetcher()->settleAll([self::fetch(SourceName::OnThisDay, $selected, $births)]);

        // Then
        self::assertSame('TransportException', $settled->result->failure?->cause?->errorType);
        self::assertSame(200, $settled->durationMs);
        self::assertSame([2, 2], $this->upstream->streamedCounts());
        self::assertTrue($selected->getInfo('canceled'));
    }

    public function testStreamsOnlyTheResponsesThatHaveNotFinished(): void
    {
        // Given
        $selected = self::response();
        $births = self::response();
        $this->upstream
            ->after(0.05, $selected, new FirstChunk())
            ->after(0.0, $selected, new LastChunk())
            ->after(0.2, $births, new FirstChunk())
            ->after(0.0, $births, new LastChunk());

        // When
        $this->fetcher()->settleAll([self::fetch(SourceName::OnThisDay, $selected, $births)]);

        // Then
        self::assertSame([2, 1], $this->upstream->streamedCounts());
    }

    public function testTakesTheTimeOfTheSlowestResponseOfASource(): void
    {
        // Given
        $selected = self::response();
        $births = self::response();
        $this->upstream
            ->after(0.1, $selected, new FirstChunk())
            ->after(0.0, $selected, new LastChunk())
            ->after(0.3, $births, new FirstChunk())
            ->after(0.0, $births, new LastChunk());

        // When
        [$settled] = $this->fetcher()->settleAll([self::fetch(SourceName::OnThisDay, $selected, $births)]);

        // Then
        self::assertSame(SourceStatus::Ok, $settled->result->status);
        self::assertSame(400, $settled->durationMs);
    }

    public function testStopsAtTheDeadlineWhileAnUpstreamKeepsSendingData(): void
    {
        // Given
        $weather = self::response();
        $this->upstream->after(0.0, $weather, new FirstChunk());

        foreach (range(1, 100) as $offset) {
            $this->upstream->after(0.05, $weather, new DataChunk($offset, '.'));
        }

        // When
        [$settled] = $this->fetcher()->settleAll([self::fetch(SourceName::Weather, $weather)]);

        // Then
        self::assertSame(self::DEADLINE_EXCEEDED, $settled->result->failure?->cause?->errorType);
        self::assertSame(3500, $settled->durationMs);
    }

    public function testStreamsInShortTicksSoTheDeadlineIsChecked(): void
    {
        // Given
        $weather = self::response();
        $this->upstream->after(1.0, $weather, new FirstChunk())->after(0.0, $weather, new LastChunk());

        // When
        $this->fetcher()->settleAll([self::fetch(SourceName::Weather, $weather)]);

        // Then
        $timeouts = $this->upstream->timeouts();
        self::assertNotEmpty($timeouts);
        self::assertSame(0.1, max($timeouts));
        self::assertGreaterThan(0.0, min($timeouts));
    }

    public function testReportsATransportErrorWithoutWaitingForTheDeadline(): void
    {
        // Given
        $weather = self::response(new MockResponse(self::BODY, ['error' => 'Connection refused']));
        $this->upstream->after(0.2, $weather, new ErrorChunk(0, new TransportException('Connection refused')));

        // When
        [$settled] = $this->fetcher()->settleAll([self::fetch(SourceName::Weather, $weather)]);

        // Then
        self::assertSame(FailureKind::Unavailable, $settled->result->failure?->kind);
        self::assertSame('TransportException', $settled->result->failure->cause?->errorType);
        self::assertSame(200, $settled->durationMs);
    }

    public function testIgnoresAnIdleTimeoutWhileTimeIsLeft(): void
    {
        // Given
        $weather = self::response();
        $this->upstream
            ->after(0.1, $weather, new ErrorChunk(0, 'Idle timeout reached'))
            ->after(0.1, $weather, new FirstChunk())
            ->after(0.0, $weather, new LastChunk());

        // When
        [$settled] = $this->fetcher()->settleAll([self::fetch(SourceName::Weather, $weather)]);

        // Then
        self::assertSame(SourceStatus::Ok, $settled->result->status);
        self::assertSame(200, $settled->durationMs);
    }

    #[DataProvider('streamFailures')]
    public function testReportsEverySourceAsUnavailableWhenTheStreamItselfFails(\Throwable $failure, string $errorType, bool $whileStreaming): void
    {
        // Given
        $picture = self::response();
        $weather = self::response();
        self::breakStream($this->upstream, $failure, $whileStreaming);

        // When
        $settled = $this->fetcher()->settleAll([self::fetch(SourceName::Apod, $picture), self::fetch(SourceName::Weather, $weather)]);

        // Then
        self::assertSame([$errorType, $errorType], array_map(static fn (SettledFetch $fetch): ?string => $fetch->result->failure?->cause?->errorType, $settled));
        self::assertSame([FailureKind::Unavailable, FailureKind::Unavailable], array_map(static fn (SettledFetch $fetch): ?FailureKind => $fetch->result->failure?->kind, $settled));
        self::assertTrue($picture->getInfo('canceled'));
        self::assertCount(1, $this->logs->getRecords());
        self::assertSame(Level::Error, $this->logs->getRecords()[0]->level);
        self::assertSame(['errorType' => $errorType], $this->logs->getRecords()[0]->context);
    }

    /**
     * @return iterable<string, array{\Throwable, string, bool}>
     */
    public static function streamFailures(): iterable
    {
        yield 'responses of several clients' => [new TransportException('Cannot stream AsyncResponse objects with many clients.'), 'TransportException', false];
        yield 'response of a client without retries' => [new \TypeError('RetryableHttpClient::stream() expects AsyncResponse objects.'), 'TypeError', false];
        yield 'responses of several clients while streaming' => [new TransportException('Cannot stream AsyncResponse objects with many clients.'), 'TransportException', true];
        yield 'response of a client without retries while streaming' => [new \TypeError('RetryableHttpClient::stream() expects AsyncResponse objects.'), 'TypeError', true];
    }

    public function testLetsATypeErrorOutsideTheStreamPropagate(): void
    {
        // Given
        $picture = self::response();
        $chunk = self::createStub(ChunkInterface::class);
        $chunk->method('isTimeout')->willThrowException(new \TypeError('A bug in tracking the chunk.'));
        $this->upstream->after(0.0, $picture, $chunk);

        // Then / When
        $this->expectException(\TypeError::class);
        $this->fetcher()->settleAll([self::fetch(SourceName::Apod, $picture)]);
    }

    private static function breakStream(ScriptedUpstream $upstream, \Throwable $failure, bool $whileStreaming): void
    {
        if ($whileStreaming) {
            $upstream->breakingWith($failure);

            return;
        }

        $upstream->failingWith($failure);
    }

    public function testSettlesNothingWithoutStreaming(): void
    {
        // When
        $settled = $this->fetcher()->settleAll([]);

        // Then
        self::assertSame([], $settled);
        self::assertSame([], $this->upstream->timeouts());
    }

    #[DataProvider('nonPositiveDeadlines')]
    public function testRejectsADeadlineThatLeavesNoTime(float $deadline): void
    {
        // Then / When
        $this->expectException(\InvalidArgumentException::class);
        new ConcurrentFetcher($this->upstream, $this->clock, new Logger('briefing'), $deadline);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonPositiveDeadlines(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
    }

    private function fetcher(): ConcurrentFetcher
    {
        return new ConcurrentFetcher($this->upstream, $this->clock, new Logger('briefing', [$this->logs]), self::DEADLINE);
    }

    private static function response(?MockResponse $response = null): ResponseInterface
    {
        return new MockHttpClient($response ?? new MockResponse(self::BODY))->request(Request::METHOD_GET, self::URL);
    }

    private static function fetch(SourceName $source, ResponseInterface $first, ResponseInterface ...$others): SourceFetch
    {
        return new SourceFetch($source, new PendingFetch([$first, ...array_values($others)], static fn (): SourcePayload => new FakePayload([]), new MockClock(self::NOW)));
    }
}
