<?php

declare(strict_types=1);

namespace App\Briefing\MessageHandler;

use App\Briefing\Elapsed;
use App\Briefing\Exception\DateNotReadyException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final readonly class PrefetchRetryStrategy implements RetryStrategyInterface
{
    public const string CODE_FAILURES = 'app.briefing.messenger.code_failures';
    public const string SPREAD = 'app.briefing.messenger.wait_spread_ms';
    private const string LONGEST_WAIT = 'PT24H';
    private const int NO_WAIT = 0;
    private const int THIS_FAILURE = 1;
    private const int NO_EARLIER_FAILURES = 0;

    public function __construct(
        #[Autowire(service: self::CODE_FAILURES)]
        private RetryStrategyInterface $codeFailures,
        private ClockInterface $clock,
        #[Autowire(param: self::SPREAD)]
        private int $spreadMs,
    ) {
    }

    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        if (DateNotReadyException::in($throwable) instanceof DateNotReadyException) {
            return $this->isStillWaiting($message);
        }

        return $this->codeFailures->isRetryable(self::asCodeFailures($message), $throwable);
    }

    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        $notReady = DateNotReadyException::in($throwable);

        if ($notReady instanceof DateNotReadyException) {
            return $this->waitUntil($notReady->retryAt);
        }

        return $this->codeFailures->getWaitingTime(self::asCodeFailures($message), $throwable);
    }

    private function waitUntil(\DateTimeImmutable $retryAt): int
    {
        $wait = max(self::NO_WAIT, Elapsed::millisecondsBetween($this->clock->now(), $retryAt));

        return $wait + random_int(self::NO_WAIT, $this->spreadMs);
    }

    private function isStillWaiting(Envelope $message): bool
    {
        $firstRetry = array_first($message->all(RedeliveryStamp::class));

        if ($firstRetry instanceof RedeliveryStamp) {
            return $this->clock->now() < self::endOfWaitingAfter($firstRetry);
        }

        return true;
    }

    private static function endOfWaitingAfter(RedeliveryStamp $firstRetry): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($firstRetry->getRedeliveredAt())->add(new \DateInterval(self::LONGEST_WAIT));
    }

    private static function asCodeFailures(Envelope $message): Envelope
    {
        $earlier = \count($message->all(CodeFailureStamp::class)) - self::THIS_FAILURE;

        if (self::NO_EARLIER_FAILURES < $earlier) {
            return new Envelope($message->getMessage(), [new RedeliveryStamp($earlier)]);
        }

        return new Envelope($message->getMessage());
    }
}
