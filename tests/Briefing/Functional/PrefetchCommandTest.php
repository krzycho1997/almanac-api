<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Functional;

use App\Briefing\Message\PrefetchDateMessage;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\InteractsWithTestServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Exception\InputValidationFailedException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PrefetchCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithTestServices;

    private const string NOW = '2026-11-20 12:00:00 UTC';
    private const string COMMAND = 'almanac:prefetch';

    protected function setUp(): void
    {
        parent::setUp();

        self::mockTime(new \DateTimeImmutable(self::NOW));
    }

    public function testPrintsTheFailedFetchesAndExitsWithFailure(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-15')
            ->apod()->status(Response::HTTP_FORBIDDEN)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-16'));

        // When
        $result = self::runCommand(self::COMMAND, ['from' => '2024-03-15', 'to' => '2024-03-16', '--delay' => '2000']);

        // Then
        $this->assertCommandFailed($result, $result->getDisplay());
        self::assertMatchesRegularExpression('/2024-03-15\s+apod\s+unavailable\s+503\s+now/', $result->getDisplay());
        self::assertMatchesRegularExpression('/2024-03-16\s+apod\s+misconfigured\s+403\s+now/', $result->getDisplay());
        self::assertStringContainsString('Prefetched dates: 2, problems: 2.', $result->getDisplay());
        self::assertEquals(new \DateTimeImmutable('2026-11-20 12:00:02 UTC'), Clock::get()->now());
        self::assertSame([], self::upstream()->unserved());
    }

    public function testSucceedsWhenEveryFetchWorked(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture('apod/passthrough-2024-03-15.json')->onThisDayFrom('03-15')->archiveOn('2024-03-15'));

        // When
        $result = self::runCommand(self::COMMAND, ['from' => '2024-03-15', 'to' => '2024-03-15', '--lat' => '50.06', '--lon' => '19.94']);

        // Then
        $this->assertCommandIsSuccessful($result, $result->getDisplay());
        self::assertStringContainsString('Prefetched dates: 1.', $result->getDisplay());
        self::assertMatchesRegularExpression('/latitude=50\.06\d*&longitude=19\.94/', implode(' ', array_map(static fn ($response): string => $response->getRequestUrl(), self::upstream()->served())));
    }

    public function testCountsEveryFailedSourceOfADateAsAProblem(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')
            ->archive()->status(Response::HTTP_SERVICE_UNAVAILABLE)->archive()->status(Response::HTTP_SERVICE_UNAVAILABLE));

        // When
        $result = self::runCommand(self::COMMAND, ['from' => '2024-03-15', 'to' => '2024-03-15']);

        // Then
        self::assertStringContainsString('Prefetched dates: 1, problems: 2.', $result->getDisplay());
    }

    public function testCountsEveryPrefetchedDateOnSuccess(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->fixture('apod/passthrough-2024-03-15.json')->onThisDayFrom('03-15')->archiveOn('2024-03-15')
            ->apod()->json(str_replace('2024-03-15', '2024-03-16', (string) file_get_contents(__DIR__.'/../../Fixtures/upstream/apod/passthrough-2024-03-15.json')))->onThisDayFrom('03-15')->archiveOn('2024-03-16'));

        // When
        $result = self::runCommand(self::COMMAND, ['from' => '2024-03-15', 'to' => '2024-03-16']);

        // Then
        self::assertStringContainsString('Prefetched dates: 2.', $result->getDisplay());
    }

    public function testReportsASourceStillWithoutDataInItsPauseAsAProblem(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-15'));
        self::runCommand(self::COMMAND, ['from' => '2024-03-15', 'to' => '2024-03-15']);

        // When
        $result = self::runCommand(self::COMMAND, ['from' => '2024-03-15', 'to' => '2024-03-15']);

        // Then
        $this->assertCommandFailed($result, $result->getDisplay());
        self::assertMatchesRegularExpression('/2024-03-15\s+apod\s+unavailable\s+-\s+earlier, in pause/', $result->getDisplay());
        self::assertStringContainsString('Prefetched dates: 1, problems: 1.', $result->getDisplay());
    }

    public function testReportsASourceSkippedByItsOpenCircuitAsAProblem(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-15')
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-16')
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->onThisDayFrom('03-15')->archiveOn('2024-03-17')
            ->onThisDayFrom('03-15')->archiveOn('2024-03-18'));

        // When
        $result = self::runCommand(self::COMMAND, ['from' => '2024-03-15', 'to' => '2024-03-18']);

        // Then
        $this->assertCommandFailed($result, $result->getDisplay());
        self::assertMatchesRegularExpression('/2024-03-17\s+apod\s+unavailable\s+503\s+now/', $result->getDisplay());
        self::assertMatchesRegularExpression('/2024-03-18\s+apod\s+circuit_open\s+-\s+skipped, circuit open/', $result->getDisplay());
        self::assertStringContainsString('Prefetched dates: 4, problems: 4.', $result->getDisplay());
        self::assertSame([], self::upstream()->unserved());
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInputBeforeFetchingAnything(array $input, string $message): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create());

        // When
        $failure = self::failureOf($input);

        // Then
        self::assertInstanceOf(InputValidationFailedException::class, $failure);
        self::assertSame($message, $failure->getMessage());
        self::assertSame([], self::upstream()->served());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'not a calendar date' => [['from' => '2024-02-30', 'to' => '2024-03-01'], 'from: This value is not a valid date.'];
        yield 'not a calendar date at the end' => [['from' => '2024-02-01', 'to' => '2024-02-30'], 'to: This value is not a valid date.'];
        yield 'longitude alone' => [['from' => '2024-03-01', 'to' => '2024-03-01', '--lon' => '21.01'], '--lat: Latitude and longitude must be given together.'];
        yield 'relative date' => [['from' => 'yesterday', 'to' => '2024-03-01'], 'from: This value is not a valid date.'];
        yield 'end before start' => [['from' => '2024-03-02', 'to' => '2024-03-01'], 'to: The last date must not be before the first.'];
        yield 'before the first picture' => [['from' => '1995-06-15', 'to' => '1995-06-16'], 'from: The date must be between 1995-06-16 and 15 days after today.'];
        yield 'beyond the forecast' => [['from' => '2026-12-05', 'to' => '2026-12-06'], 'to: The date must be between 1995-06-16 and 15 days after today.'];
        yield 'longer than a year' => [['from' => '2024-01-01', 'to' => '2025-01-01'], 'to: The range may span at most 366 days.'];
        yield 'latitude alone' => [['from' => '2024-03-01', 'to' => '2024-03-01', '--lat' => '52.23'], '--lon: Latitude and longitude must be given together.'];
        yield 'latitude off the globe' => [['from' => '2024-03-01', 'to' => '2024-03-01', '--lat' => '91', '--lon' => '0'], '--lat: This value should be between -90 and 90.'];
        yield 'negative delay' => [['from' => '2024-03-01', 'to' => '2024-03-01', '--delay' => '-1'], '--delay: The delay must be a whole number of milliseconds.'];
        yield 'fractional delay' => [['from' => '2024-03-01', 'to' => '2024-03-01', '--delay' => '1.5'], '--delay: The delay must be a whole number of milliseconds.'];
        yield 'delay over ten minutes' => [['from' => '2024-03-01', 'to' => '2024-03-01', '--delay' => '600001'], '--delay: This value should be between 0 and 600000.'];
        yield 'end before a start out of the window' => [['from' => '2026-12-06', 'to' => '2026-12-05'], "to: The last date must not be before the first.\nfrom: The date must be between 1995-06-16 and 15 days after today."];
        yield 'both dates out of the window' => [['from' => '1995-06-14', 'to' => '1995-06-15'], "from: The date must be between 1995-06-16 and 15 days after today.\nto: The date must be between 1995-06-16 and 15 days after today."];
    }

    /**
     * @param array<string, string> $input
     */
    private static function failureOf(array $input): ?\Throwable
    {
        try {
            self::runCommand(self::COMMAND, $input);
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }

    public function testQueuesTheDatesWithAsyncAndExitsAtOnce(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create());

        // When
        $result = self::runCommand(self::COMMAND, ['from' => '2024-03-15', 'to' => '2024-03-16', '--async' => true, '--delay' => '2000', '--lat' => '50.06', '--lon' => '19.94']);

        // Then
        $this->assertCommandIsSuccessful($result, $result->getDisplay());
        self::assertStringContainsString('Queued dates: 2.', $result->getDisplay());
        self::assertEquals([[new PrefetchDateMessage('2024-03-15', 50.06, 19.94), 0], [new PrefetchDateMessage('2024-03-16', 50.06, 19.94), 2000]], array_map(static fn (Envelope $envelope): array => [$envelope->getMessage(), $envelope->last(DelayStamp::class)?->getDelay()], self::queue()->getSent()));
        self::assertSame([], self::upstream()->served());
        self::assertEquals(new \DateTimeImmutable(self::NOW), Clock::get()->now());
    }

    private static function queue(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private static function upstream(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }
}
