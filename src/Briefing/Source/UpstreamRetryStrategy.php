<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final readonly class UpstreamRetryStrategy implements RetryStrategyInterface
{
    public const array RETRIED_STATUSES = [
        self::TRANSPORT_ERROR,
        Response::HTTP_INTERNAL_SERVER_ERROR,
        Response::HTTP_BAD_GATEWAY,
        Response::HTTP_SERVICE_UNAVAILABLE,
        Response::HTTP_GATEWAY_TIMEOUT,
    ];
    private const int TRANSPORT_ERROR = 0;

    public function __construct(
        private RetryStrategyInterface $transientFailures,
    ) {
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        if (self::asksToWait($context)) {
            return false;
        }

        return $this->transientFailures->shouldRetry($context, $responseContent, $exception);
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        return $this->transientFailures->getDelay($context, $responseContent, $exception);
    }

    private static function asksToWait(AsyncContext $context): bool
    {
        return isset($context->getHeaders()[RetryAfter::HEADER]);
    }
}
