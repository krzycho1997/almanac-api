<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\Recording;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final readonly class ShortRetryAfter implements RetryStrategyInterface
{
    public const int LONGEST_WAIT_SECONDS = 10;
    private const array THROTTLED = [Response::HTTP_TOO_MANY_REQUESTS, Response::HTTP_SERVICE_UNAVAILABLE];
    private const string RETRY_AFTER = 'retry-after';
    private const int MILLISECONDS_PER_SECOND = 1000;

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): bool
    {
        if ($exception instanceof TransportExceptionInterface) {
            return false;
        }

        if (\in_array($context->getStatusCode(), self::THROTTLED, true)) {
            return self::waitsBriefly($context->getHeaders()[self::RETRY_AFTER][0] ?? null);
        }

        return false;
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        return self::LONGEST_WAIT_SECONDS * self::MILLISECONDS_PER_SECOND;
    }

    private static function waitsBriefly(?string $retryAfter): bool
    {
        if (ctype_digit((string) $retryAfter)) {
            return (int) $retryAfter <= self::LONGEST_WAIT_SECONDS;
        }

        return false;
    }
}
