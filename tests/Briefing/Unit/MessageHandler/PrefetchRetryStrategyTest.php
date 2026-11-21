<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\MessageHandler;

use App\Briefing\Exception\DateNotReadyException;
use App\Briefing\MessageHandler\CodeFailureStamp;
use App\Briefing\MessageHandler\PrefetchRetryStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class PrefetchRetryStrategyTest extends TestCase
{
    private const string NOW = '2026-11-20 12:00:00';
    private const int NO_SPREAD = 0;
    private const string HANDLER = 'App\Briefing\MessageHandler\PrefetchDateHandler::__invoke';

    #[DataProvider('refusals')]
    public function testWaitsForTheOpeningsOfARefusingCircuitForADay(?string $firstRetry, string $retryAt, bool $retryable, int $waitMs): void
    {
        // Given
        $strategy = self::strategy();
        $envelope = self::envelopeFirstRetried($firstRetry);
        $refused = self::failure(DateNotReadyException::until(new \DateTimeImmutable(self::NOW)->modify($retryAt)));

        // When
        $isRetryable = $strategy->isRetryable($envelope, $refused);
        $waiting = $strategy->getWaitingTime($envelope, $refused);

        // Then
        self::assertSame([$retryable, $waitMs], [$isRetryable, $waiting]);
    }

    /**
     * @return iterable<string, array{?string, string, bool, int}>
     */
    public static function refusals(): iterable
    {
        yield 'first refusal, a minute to go' => [null, '+1 minute', true, 60_000];
        yield 'a rate limit fifteen minutes away' => ['-1 hour', '+15 minutes', true, 900_000];
        yield 'an opening that just ended' => ['-2 hours', '-1 second', true, 0];
        yield 'almost a day of waiting' => ['-23 hours -59 minutes', '+1 minute', true, 60_000];
        yield 'a day of waiting' => ['-24 hours', '+1 minute', false, 60_000];
    }

    #[DataProvider('codeFailures')]
    public function testRetriesOtherFailuresThreeTimesAfterOneFourAndSixteenSeconds(int $retries, bool $retryable, int $waitMs): void
    {
        // Given
        $strategy = self::strategy();
        $envelope = self::envelopeWithCodeFailures($retries + 1);
        $failure = self::failure(new \LogicException('A bug.'));

        // When
        $isRetryable = $strategy->isRetryable($envelope, $failure);
        $waiting = $strategy->getWaitingTime($envelope, $failure);

        // Then
        self::assertSame([$retryable, $waitMs], [$isRetryable, $waiting]);
    }

    /**
     * @return iterable<string, array{int, bool, int}>
     */
    public static function codeFailures(): iterable
    {
        yield 'first failure' => [0, true, 1_000];
        yield 'second failure' => [1, true, 4_000];
        yield 'third failure' => [2, true, 16_000];
        yield 'after three retries' => [3, false, 64_000];
    }

    private static function strategy(): PrefetchRetryStrategy
    {
        return new PrefetchRetryStrategy(new MultiplierRetryStrategy(3, 1_000, 4, jitter: 0.0), new MockClock(self::NOW), self::NO_SPREAD);
    }

    private static function envelopeFirstRetried(?string $ago): Envelope
    {
        $envelope = new Envelope(new \stdClass());

        if (null === $ago) {
            return $envelope;
        }

        return $envelope->with(new RedeliveryStamp(1, new \DateTimeImmutable(self::NOW)->modify($ago)), new RedeliveryStamp(7, new \DateTimeImmutable(self::NOW)));
    }

    public function testCountsCodeFailuresApartFromWaitsForTheUpstream(): void
    {
        // Given
        $strategy = self::strategy();
        $envelope = new Envelope(new \stdClass(), [new RedeliveryStamp(1), new RedeliveryStamp(2), new RedeliveryStamp(3), new CodeFailureStamp()]);
        $failure = self::failure(new \LogicException('A database blip.'));

        // When
        $retry = [$strategy->isRetryable($envelope, $failure), $strategy->getWaitingTime($envelope, $failure)];

        // Then
        self::assertSame([true, 1_000], $retry);
    }

    public function testSpreadsTheWaitsOfDatesThatWakeTogether(): void
    {
        // Given
        $strategy = new PrefetchRetryStrategy(new MultiplierRetryStrategy(3, 1_000, 4, jitter: 0.0), new MockClock(self::NOW), 30_000);
        $refused = self::failure(DateNotReadyException::until(new \DateTimeImmutable(self::NOW)->modify('+1 minute')));

        // When
        $waits = array_map(static fn (): int => $strategy->getWaitingTime(new Envelope(new \stdClass()), $refused), range(1, 50));

        // Then
        self::assertGreaterThanOrEqual(60_000, min($waits));
        self::assertLessThanOrEqual(90_000, max($waits));
        self::assertGreaterThan(1, \count(array_unique($waits)));
    }

    private static function envelopeWithCodeFailures(int $failures): Envelope
    {
        return new Envelope(new \stdClass(), array_map(static fn (): CodeFailureStamp => new CodeFailureStamp(), range(1, $failures)));
    }

    private static function failure(\Throwable $thrown): HandlerFailedException
    {
        return new HandlerFailedException(new Envelope(new \stdClass()), [self::HANDLER => $thrown]);
    }
}
