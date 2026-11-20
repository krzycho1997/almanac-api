<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use App\Briefing\Exception\UpstreamStreamException;
use App\Briefing\FailureCause;
use App\Briefing\FailureKind;
use App\Briefing\SourceResult;
use App\Shared\Logging\LogContextKey;
use Monolog\Attribute\WithMonologChannel;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

#[WithMonologChannel(self::CHANNEL)]
final readonly class ConcurrentFetcher
{
    private const string CHANNEL = 'briefing';
    private const string STREAM_FAILED = 'Streaming upstream responses failed: {errorType}';
    private const float TICK_SECONDS = 0.1;
    private const float NO_TIME_LEFT = 0.0;
    private const int MILLISECONDS_PER_SECOND = 1000;
    private const string SECONDS_WITH_MICROSECONDS = 'U.u';

    public function __construct(
        private HttpClientInterface $upstream,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private float $deadlineSeconds,
    ) {
        if ($deadlineSeconds <= self::NO_TIME_LEFT) {
            throw new \InvalidArgumentException("The fetch deadline must be positive, got {$deadlineSeconds} s.");
        }
    }

    /**
     * @param list<SourceFetch> $fetches
     *
     * @return list<SettledFetch>
     */
    public function settleAll(array $fetches): array
    {
        $startedAt = $this->clock->now();
        $progress = new StreamProgress();

        try {
            $streamFailure = $this->streamUntilSettled($fetches, $progress, $this->deadlineFrom($startedAt));

            return array_map(fn (SourceFetch $fetch): SettledFetch => $this->settle($fetch, $progress, $startedAt, $streamFailure), $fetches);
        } finally {
            self::cancelAll($fetches);
        }
    }

    /**
     * @param list<SourceFetch> $fetches
     */
    private function streamUntilSettled(array $fetches, StreamProgress $progress, \DateTimeImmutable $deadline): ?FailureCause
    {
        try {
            $this->streamWhileTimeIsLeft($fetches, $progress, $deadline);

            return null;
        } catch (UpstreamStreamException $exception) {
            return $this->streamFailure($exception->failure);
        }
    }

    /**
     * @param list<SourceFetch> $fetches
     */
    private function streamWhileTimeIsLeft(array $fetches, StreamProgress $progress, \DateTimeImmutable $deadline): void
    {
        $pending = $progress->unfinishedOf($fetches);
        $secondsLeft = $this->secondsUntil($deadline);

        while (self::isWaitingFor($pending, $secondsLeft)) {
            $this->streamOnce($pending, $progress, $deadline, min(self::TICK_SECONDS, $secondsLeft));
            $pending = $progress->unfinishedOf($fetches);
            $secondsLeft = $this->secondsUntil($deadline);
        }
    }

    /**
     * @param non-empty-list<ResponseInterface> $pending
     */
    private function streamOnce(array $pending, StreamProgress $progress, \DateTimeImmutable $deadline, float $tick): void
    {
        $stream = self::guarded(fn (): ResponseStreamInterface => $this->upstream->stream($pending, $tick));

        for (self::guarded($stream->rewind(...)); self::guarded($stream->valid(...)); self::guarded($stream->next(...))) {
            $this->track($stream->key(), $stream->current(), $progress);

            if ($this->secondsUntil($deadline) <= self::NO_TIME_LEFT) {
                return;
            }
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $step
     *
     * @return T
     */
    private static function guarded(\Closure $step): mixed
    {
        try {
            return $step();
        } catch (TransportExceptionInterface|\TypeError $failure) {
            throw UpstreamStreamException::brokenBy($failure);
        }
    }

    private function track(ResponseInterface $response, ChunkInterface $chunk, StreamProgress $progress): void
    {
        try {
            $this->trackChunk($response, $chunk, $progress);
        } catch (TransportExceptionInterface) {
            $progress->fail($response, $this->clock->now());
        }
    }

    private function trackChunk(ResponseInterface $response, ChunkInterface $chunk, StreamProgress $progress): void
    {
        if ($chunk->isTimeout()) {
            return;
        }

        if ($chunk->isFirst()) {
            $this->acknowledgeStatus($response, $progress);
        }

        if ($chunk->isLast()) {
            $progress->finish($response, $this->clock->now());
        }
    }

    private function acknowledgeStatus(ResponseInterface $response, StreamProgress $progress): void
    {
        if (PendingFetch::isSuccessful($response->getStatusCode())) {
            return;
        }

        $progress->fail($response, $this->clock->now());
    }

    private function settle(SourceFetch $fetch, StreamProgress $progress, \DateTimeImmutable $startedAt, ?FailureCause $streamFailure): SettledFetch
    {
        $settledAt = $progress->settledAt($fetch);

        if ($settledAt instanceof \DateTimeImmutable) {
            $progress->cancelUnfinished($fetch);

            return new SettledFetch($fetch->pending->resolve($fetch->source), self::millisecondsBetween($startedAt, $settledAt));
        }

        return new SettledFetch(
            SourceResult::failed($fetch->source, FailureKind::Unavailable, cause: $streamFailure ?? FailureCause::deadlineExceeded()),
            self::millisecondsBetween($startedAt, $this->clock->now()),
        );
    }

    private function streamFailure(\Throwable $exception): FailureCause
    {
        $cause = FailureCause::of($exception);
        $this->logger->error(self::STREAM_FAILED, [LogContextKey::ErrorType->value => $cause->errorType]);

        return $cause;
    }

    /**
     * @param list<ResponseInterface> $pending
     *
     * @phpstan-assert-if-true non-empty-list<ResponseInterface> $pending
     */
    private static function isWaitingFor(array $pending, float $secondsLeft): bool
    {
        if ([] === $pending) {
            return false;
        }

        return $secondsLeft > self::NO_TIME_LEFT;
    }

    /**
     * @param list<SourceFetch> $fetches
     */
    private static function cancelAll(array $fetches): void
    {
        foreach ($fetches as $fetch) {
            $fetch->pending->cancel();
        }
    }

    private function deadlineFrom(\DateTimeImmutable $startedAt): \DateTimeImmutable
    {
        return $startedAt->modify(\sprintf('+%d milliseconds', (int) round($this->deadlineSeconds * self::MILLISECONDS_PER_SECOND)));
    }

    private function secondsUntil(\DateTimeImmutable $deadline): float
    {
        return self::seconds($deadline) - self::seconds($this->clock->now());
    }

    private static function millisecondsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) round((self::seconds($to) - self::seconds($from)) * self::MILLISECONDS_PER_SECOND);
    }

    private static function seconds(\DateTimeImmutable $moment): float
    {
        return (float) $moment->format(self::SECONDS_WITH_MICROSECONDS);
    }
}
