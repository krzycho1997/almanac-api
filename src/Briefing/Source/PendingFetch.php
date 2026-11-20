<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\FailureKind;
use App\Briefing\Model\SourcePayload;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class PendingFetch
{
    /**
     * @param \Closure(ResponseInterface): SourcePayload $map
     */
    public function __construct(
        private ResponseInterface $response,
        private \Closure $map,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(SourceName $source): SourceResult
    {
        try {
            return $this->resultFor($source, $this->response->getStatusCode());
        } catch (TransportExceptionInterface) {
            return SourceResult::failed($source, FailureKind::Unavailable);
        }
    }

    private function resultFor(SourceName $source, int $status): SourceResult
    {
        if (self::isSuccessful($status)) {
            return $this->mapped($source, $status);
        }

        if (Response::HTTP_TOO_MANY_REQUESTS === $status) {
            return SourceResult::failed($source, FailureKind::RateLimited, $status, $this->retryAt());
        }

        return SourceResult::failed($source, self::failureKindFor($status), $status);
    }

    private function mapped(SourceName $source, int $status): SourceResult
    {
        try {
            return SourceResult::ok($source, ($this->map)($this->response));
        } catch (DecodingExceptionInterface|InvalidUpstreamPayloadException|\JsonException) {
            return SourceResult::failed($source, FailureKind::InvalidPayload, $status);
        }
    }

    private static function failureKindFor(int $status): FailureKind
    {
        if (self::isTransient($status)) {
            return FailureKind::Unavailable;
        }

        if (self::isOurConfigurationFault($status)) {
            return FailureKind::Misconfigured;
        }

        return FailureKind::Rejected;
    }

    private static function isSuccessful(int $status): bool
    {
        return $status >= Response::HTTP_OK && $status < Response::HTTP_MULTIPLE_CHOICES;
    }

    private static function isTransient(int $status): bool
    {
        if (Response::HTTP_REQUEST_TIMEOUT === $status) {
            return true;
        }

        return $status >= Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    private static function isOurConfigurationFault(int $status): bool
    {
        if (\in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
            return true;
        }

        return $status >= Response::HTTP_MULTIPLE_CHOICES && $status < Response::HTTP_BAD_REQUEST;
    }

    private function retryAt(): ?\DateTimeImmutable
    {
        $retryAfter = $this->response->getHeaders(false)[RetryAfter::HEADER][0] ?? null;

        if (null === $retryAfter) {
            return null;
        }

        return RetryAfter::parse($retryAfter, $this->clock->now());
    }
}
