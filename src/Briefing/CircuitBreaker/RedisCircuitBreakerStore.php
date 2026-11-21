<?php

declare(strict_types=1);

namespace App\Briefing\CircuitBreaker;

use App\Briefing\Elapsed;
use App\Shared\Logging\LogContextKey;
use Monolog\Attribute\WithMonologChannel;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

#[WithMonologChannel(self::CHANNEL)]
final class RedisCircuitBreakerStore implements CircuitBreakerStore, ResetInterface
{
    private const string CHANNEL = 'briefing';
    private const string UNAVAILABLE = 'Circuit store unavailable, failing open';
    private const string KEY = 'briefing:cb:{%s}:%s';
    private const string FAILURES = 'failures';
    private const string OPEN_UNTIL = 'open_until';
    private const string TRIAL = 'trial';
    private const string INSTANT = 'U.u';
    private const int ONE_KEY = 1;
    private const int FIRST_FAILURE = 1;
    private const int NOTHING_LEFT = 0;
    private const string COUNT_UNTIL = <<<'LUA'
        local count = redis.call('INCR', KEYS[1])
        redis.call('PEXPIRE', KEYS[1], ARGV[1])
        return count
        LUA;
    private const string OPEN_AT_LEAST_UNTIL = <<<'LUA'
        local previous = tonumber(redis.call('GET', KEYS[1]))
        local untilAt = tonumber(ARGV[1])
        if previous and previous >= untilAt then
            return 0
        end
        redis.call('SET', KEYS[1], ARGV[1])
        redis.call('DEL', KEYS[2], KEYS[3])
        if previous and previous > tonumber(ARGV[2]) then
            return 0
        end
        return 1
        LUA;
    private const string CLAIM_HALF_OPEN_TRIAL = <<<'LUA'
        local openUntil = tonumber(redis.call('GET', KEYS[1]))
        if not openUntil or openUntil > tonumber(ARGV[1]) then
            return 0
        end
        if tonumber(ARGV[2]) <= 0 then
            if redis.call('EXISTS', KEYS[2]) == 0 then
                return 1
            end
            return 0
        end
        if redis.call('SET', KEYS[2], '1', 'NX', 'PX', ARGV[2]) then
            return 1
        end
        return 0
        LUA;
    private const int TWO_KEYS = 2;
    private const string REST_AFTER_FAILURE = 'PT30S';
    private const int THREE_KEYS = 3;
    private const int STARTED = 1;
    private const int ONE_DELETED = 1;

    private ?\DateTimeImmutable $unavailableUntil = null;

    public function __construct(
        #[Autowire(service: 'app.redis_connection')]
        private readonly \Redis $redis,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function openUntil(string $circuit): ?\DateTimeImmutable
    {
        return $this->attempt(__FUNCTION__, fn (): ?\DateTimeImmutable => $this->instantOf($this->redis->get(self::key($circuit, self::OPEN_UNTIL))), null);
    }

    public function recordFailure(string $circuit, \DateTimeImmutable $forgetAt): int
    {
        return $this->attempt(__FUNCTION__, fn (): int => $this->countUntil(self::key($circuit, self::FAILURES), $forgetAt), self::NOTHING_LEFT);
    }

    public function forgetFailures(string $circuit): void
    {
        $this->attempt(__FUNCTION__, fn (): mixed => $this->redis->del(self::key($circuit, self::FAILURES)), null);
    }

    public function open(string $circuit, \DateTimeImmutable $until): bool
    {
        return $this->attempt(__FUNCTION__, fn (): bool => self::STARTED === $this->redis->eval(self::OPEN_AT_LEAST_UNTIL, [
            self::key($circuit, self::OPEN_UNTIL),
            self::key($circuit, self::FAILURES),
            self::key($circuit, self::TRIAL),
            $until->format(self::INSTANT),
            $this->clock->now()->format(self::INSTANT),
        ], self::THREE_KEYS), false);
    }

    public function claimTrial(string $circuit, \DateTimeImmutable $leaseUntil): bool
    {
        return $this->attempt(__FUNCTION__, fn (): bool => self::STARTED === $this->redis->eval(self::CLAIM_HALF_OPEN_TRIAL, [
            self::key($circuit, self::OPEN_UNTIL),
            self::key($circuit, self::TRIAL),
            $this->clock->now()->format(self::INSTANT),
            $this->millisecondsUntil($leaseUntil),
        ], self::TWO_KEYS), true);
    }

    public function close(string $circuit): bool
    {
        return $this->attempt(__FUNCTION__, fn (): bool => $this->closed($circuit), false);
    }

    public function reset(): void
    {
        $this->unavailableUntil = null;
    }

    private function countUntil(string $key, \DateTimeImmutable $forgetAt): int
    {
        $milliseconds = $this->millisecondsUntil($forgetAt);

        if (self::NOTHING_LEFT < $milliseconds) {
            $count = $this->redis->eval(self::COUNT_UNTIL, [$key, $milliseconds], self::ONE_KEY);

            return \is_int($count) ? $count : self::NOTHING_LEFT;
        }

        $this->redis->del($key);

        return self::FIRST_FAILURE;
    }

    private function closed(string $circuit): bool
    {
        $wasOpen = self::ONE_DELETED === $this->redis->del(self::key($circuit, self::OPEN_UNTIL));
        $this->redis->del(self::key($circuit, self::FAILURES), self::key($circuit, self::TRIAL));

        return $wasOpen;
    }

    private function millisecondsUntil(\DateTimeImmutable $moment): int
    {
        return (int) ceil(Elapsed::secondsBetween($this->clock->now(), $moment) * Elapsed::MILLISECONDS_PER_SECOND);
    }

    private static function key(string $circuit, string $field): string
    {
        return \sprintf(self::KEY, $circuit, $field);
    }

    private function instantOf(mixed $stored): ?\DateTimeImmutable
    {
        if (\is_string($stored)) {
            return $this->parsedInstant($stored);
        }

        return null;
    }

    private function parsedInstant(string $stored): ?\DateTimeImmutable
    {
        $instant = \DateTimeImmutable::createFromFormat(self::INSTANT, $stored);

        if ($instant instanceof \DateTimeImmutable) {
            return $instant->setTimezone($this->clock->now()->getTimezone());
        }

        return null;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     * @param T             $fallback
     *
     * @return T
     */
    private function attempt(string $name, \Closure $operation, mixed $fallback): mixed
    {
        if ($this->isResting()) {
            return $fallback;
        }

        try {
            return $this->answerOf($operation);
        } catch (\RedisException|InvalidArgumentException $exception) {
            $this->failOpen($name, $exception);

            return $fallback;
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function answerOf(\Closure $operation): mixed
    {
        $this->redis->clearLastError();
        $answer = $operation();
        $error = $this->redis->getLastError();

        if (null === $error) {
            return $answer;
        }

        throw new \RedisException($error);
    }

    private function isResting(): bool
    {
        if (null === $this->unavailableUntil) {
            return false;
        }

        return $this->clock->now() < $this->unavailableUntil;
    }

    private function failOpen(string $operation, \Throwable $exception): void
    {
        $this->unavailableUntil = $this->clock->now()->add(new \DateInterval(self::REST_AFTER_FAILURE));
        $this->logger->warning(self::UNAVAILABLE, [LogContextKey::Operation->value => $operation, LogContextKey::Exception->value => $exception]);
    }
}
