<?php

declare(strict_types=1);

namespace App\Briefing\Exception;

use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class DateNotReadyException extends \RuntimeException
{
    private const string MESSAGE = 'A source of the date is down or refused by its circuit; the date waits until it can be fetched again.';

    private function __construct(public readonly \DateTimeImmutable $retryAt)
    {
        parent::__construct(self::MESSAGE);
    }

    public static function until(\DateTimeImmutable $retryAt): self
    {
        return new self($retryAt);
    }

    public static function in(?\Throwable $throwable): ?self
    {
        if ($throwable instanceof HandlerFailedException) {
            return self::firstOf($throwable->getWrappedExceptions(self::class));
        }

        return null;
    }

    /**
     * @param array<array-key, \Throwable> $exceptions
     */
    private static function firstOf(array $exceptions): ?self
    {
        $first = array_first($exceptions);

        if ($first instanceof self) {
            return $first;
        }

        return null;
    }
}
