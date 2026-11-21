<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\MessageHandler;

use App\Briefing\BriefingAggregator;
use App\Briefing\BriefingResult;
use App\Briefing\CircuitBreaker\CircuitBreakerStore;
use App\Briefing\Exception\DateNotReadyException;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Message\PrefetchDateMessage;
use App\Briefing\MessageHandler\PrefetchDateHandler;
use App\Briefing\SourceName;
use App\Tests\Support\Briefing\CircuitBreaker\BrokenCircuitBreakerStore;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\InteractsWithTestServices;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

final class PrefetchDateHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithTestServices;

    private const string NOW = '2026-11-20 12:00:00 UTC';
    private const string DAY = '2024-03-15';
    private const int RETRIES = 3;
    private const string LONG_ENOUGH_FOR_ALL_RETRIES_SECONDS = '900';
    private const ?string KEEP_THE_IN_MEMORY_QUEUES = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::mockTime(new \DateTimeImmutable(self::NOW));
    }

    public function testStoresTheSnapshotsOfTheDate(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture('apod/passthrough-2024-03-15.json')->onThisDayFrom('03-15')->archiveOn(self::DAY));

        // When
        self::handler()(new PrefetchDateMessage(self::DAY, 52.23, 21.01));

        // Then
        self::upstream()->respondWith(UpstreamMock::create());
        self::assertSame([], self::storedBriefing()->missing());
        self::assertSame([], self::upstream()->served());
    }

    public function testStoresAnUpstreamOutageAndAsksToTryTheDateAgainAfterItsPause(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn(self::DAY));

        // When
        $notReady = self::notReadyAfter(new PrefetchDateMessage(self::DAY, 52.23, 21.01));

        // Then
        self::assertEquals(new \DateTimeImmutable(self::NOW)->modify('+10 minutes'), $notReady?->retryAt);
        self::upstream()->respondWith(UpstreamMock::create());
        $stored = self::storedBriefing();
        self::assertSame([SourceName::Apod], $stored->missing());
        self::assertSame(FailureKind::Unavailable, $stored->entryFor(SourceName::Apod)->lastFailure?->kind);
    }

    public function testAcknowledgesADateWhoseSourceRejectedIt(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_BAD_REQUEST)
            ->onThisDayFrom('03-15')->archiveOn(self::DAY));

        // When
        $notReady = self::notReadyAfter(new PrefetchDateMessage(self::DAY, 52.23, 21.01));

        // Then
        self::assertNull($notReady);
    }

    public function testFetchesADateAgainOnceThePauseAfterAnOutageIsOver(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn(self::DAY)
            ->apod()->fixture('apod/passthrough-2024-03-15.json'));
        self::bus()->dispatch(new PrefetchDateMessage(self::DAY, 52.23, 21.01));

        // When
        self::consume();

        // Then
        self::assertSame([], self::upstream()->unserved());
        self::assertSame([2, 0, 0], [\count(self::async()->getAcknowledged()), \count(self::async()->getRejected()), self::failed()->getMessageCount()]);
        self::upstream()->respondWith(UpstreamMock::create());
        self::assertSame([], self::storedBriefing()->missing());
    }

    public function testUsesTheDefaultPlaceWithoutCoordinates(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture('apod/passthrough-2024-03-15.json')->onThisDayFrom('03-15')->archiveOn(self::DAY));

        // When
        self::handler()(new PrefetchDateMessage(self::DAY, null, null));

        // Then
        self::assertMatchesRegularExpression('/latitude=48\.86\d*&longitude=2\.35/', implode(' ', array_map(static fn ($response): string => $response->getRequestUrl(), self::upstream()->served())));
    }

    public function testRetriesADateRefusedByAnOpenCircuitOnceTheOpeningEnds(): void
    {
        // Given
        self::testService(CircuitBreakerStore::class)->open(SourceName::Apod->value, new \DateTimeImmutable(self::NOW)->modify('+1 minute'));
        self::upstream()->respondWith(UpstreamMock::create()->onThisDayFrom('03-15')->archiveOn(self::DAY)->apod()->fixture('apod/passthrough-2024-03-15.json'));
        self::bus()->dispatch(new PrefetchDateMessage(self::DAY, 52.23, 21.01));

        // When
        self::consume();

        // Then
        self::assertSame([], self::upstream()->unserved());
        self::assertSame([2, 0, 0], [\count(self::async()->getAcknowledged()), \count(self::async()->getRejected()), self::failed()->getMessageCount()]);
        self::upstream()->respondWith(UpstreamMock::create());
        self::assertSame([], self::storedBriefing()->missing());
    }

    public function testMovesAMessageThatKeepsFailingToTheFailureTransportAfterItsRetries(): void
    {
        // Given
        self::getContainer()->set(CircuitBreakerStore::class, new BrokenCircuitBreakerStore());
        self::bus()->dispatch(new PrefetchDateMessage(self::DAY, 52.23, 21.01));

        // When
        self::consume();

        // Then
        self::assertSame([], [...self::async()->get()]);
        self::assertSame([self::RETRIES, 1], [\count(self::async()->getAcknowledged()), \count(self::async()->getRejected())]);
        self::assertSame(1, self::failed()->getMessageCount());
    }

    public function testMovesAMessageWithAnInvalidPlaceToTheFailureTransportAtOnce(): void
    {
        // Given
        self::bus()->dispatch(new PrefetchDateMessage(self::DAY, 91.0, 0.0));

        // When
        self::consume();

        // Then
        self::assertSame([0, 1], [\count(self::async()->getAcknowledged()), \count(self::async()->getRejected())]);
        self::assertSame(1, self::failed()->getMessageCount());
    }

    public function testMovesAMessageWithoutADateToTheFailureTransportAtOnce(): void
    {
        // Given
        self::bus()->dispatch(new PrefetchDateMessage('2024-02-30', null, null));

        // When
        self::consume();

        // Then
        self::assertSame([0, 1], [\count(self::async()->getAcknowledged()), \count(self::async()->getRejected())]);
        self::assertSame(1, self::failed()->getMessageCount());
    }

    private static function notReadyAfter(PrefetchDateMessage $message): ?DateNotReadyException
    {
        try {
            self::handler()($message);
        } catch (DateNotReadyException $notReady) {
            return $notReady;
        }

        return null;
    }

    private static function consume(): void
    {
        self::runCommand('messenger:consume', ['receivers' => ['async'], '--time-limit' => self::LONG_ENOUGH_FOR_ALL_RETRIES_SECONDS, '--no-reset' => self::KEEP_THE_IN_MEMORY_QUEUES]);
    }

    private static function storedBriefing(): BriefingResult
    {
        return self::testService(BriefingAggregator::class)->briefingFor(new \DateTimeImmutable(self::DAY), Location::fromCoordinates(52.23, 21.01));
    }

    private static function handler(): PrefetchDateHandler
    {
        return self::testService(PrefetchDateHandler::class);
    }

    private static function bus(): MessageBusInterface
    {
        return self::testService(MessageBusInterface::class);
    }

    private static function async(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private static function failed(): MessageCountAwareInterface
    {
        $transport = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(MessageCountAwareInterface::class, $transport);

        return $transport;
    }

    private static function upstream(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }
}
