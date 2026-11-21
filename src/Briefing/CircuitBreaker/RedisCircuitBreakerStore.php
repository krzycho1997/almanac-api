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
    private const string CLAIMED = '1';
    private const string INSTANT = 'U.u';
    private const string NOT_EXISTING = 'nx';
    private const string EXPIRES_IN = 'px';
    private const int ONE_KEY = 1;
    private const int FIRST_FAILURE = 1;
    private const int NOTHING_LEFT = 0;
    private const string COUNT_UNTIL = <<<'LUA'
        local count = redis.call('INCR', KEYS[1])
        redis.call('PEXPIRE', KEYS[1], ARGV[1])
        return count
        LUA;

    private bool $unavailable = false;

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

    public function open(string $circuit, \DateTimeImmutable $until): void
    {
        $this->attempt(__FUNCTION__, fn (): mixed => $this->redis->set(self::key($circuit, self::OPEN_UNTIL), $until->format(self::INSTANT)), null);
        $this->attempt(__FUNCTION__, fn (): mixed => $this->redis->del(self::key($circuit, self::FAILURES), self::key($circuit, self::TRIAL)), null);
    }

    public function claimTrial(string $circuit, \DateTimeImmutable $leaseUntil): bool
    {
        return $this->attempt(__FUNCTION__, fn (): bool => $this->lease(self::key($circuit, self::TRIAL), $leaseUntil), true);
    }

    public function close(string $circuit): void
    {
        $this->attempt(__FUNCTION__, fn (): mixed => $this->redis->del(self::key($circuit, self::FAILURES), self::key($circuit, self::OPEN_UNTIL), self::key($circuit, self::TRIAL)), null);
    }

    public function reset(): void
    {
        $this->unavailable = false;
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

    private function lease(string $key, \DateTimeImmutable $leaseUntil): bool
    {
        $milliseconds = $this->millisecondsUntil($leaseUntil);

        if (self::NOTHING_LEFT < $milliseconds) {
            return true === $this->redis->set($key, self::CLAIMED, [self::NOT_EXISTING, self::EXPIRES_IN => $milliseconds]);
        }

        return 0 === $this->redis->exists($key);
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
        if ($this->unavailable) {
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

    private function failOpen(string $operation, \Throwable $exception): void
    {
        $this->unavailable = true;
        $this->logger->warning(self::UNAVAILABLE, [LogContextKey::Operation->value => $operation, LogContextKey::Exception->value => $exception]);
    }
}
