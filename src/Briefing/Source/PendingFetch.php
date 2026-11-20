<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\FailureCause;
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
    private const string CANCELED = 'canceled';

    /**
     * @param non-empty-list<ResponseInterface> $responses
     * @param \Closure(): SourcePayload         $map
     */
    public function __construct(
        private array $responses,
        private \Closure $map,
        private ClockInterface $clock,
    ) {
    }

    public function resolve(SourceName $source): SourceResult
    {
        try {
            return $this->firstFailure($source) ?? $this->mapped($source);
        } catch (TransportExceptionInterface $exception) {
            return SourceResult::failed($source, FailureKind::Unavailable, cause: FailureCause::of($exception));
        } finally {
            $this->cancel();
        }
    }

    private function firstFailure(SourceName $source): ?SourceResult
    {
        foreach ($this->uncanceledFirst() as $response) {
            $status = $response->getStatusCode();

            if (self::isSuccessful($status)) {
                continue;
            }

            return $this->failure($source, $response, $status);
        }

        return null;
    }

    private function failure(SourceName $source, ResponseInterface $response, int $status): SourceResult
    {
        if (Response::HTTP_TOO_MANY_REQUESTS === $status) {
            return SourceResult::failed($source, FailureKind::RateLimited, $status, $this->retryAt($response));
        }

        return SourceResult::failed($source, self::failureKindFor($status), $status);
    }

    private function mapped(SourceName $source): SourceResult
    {
        $status = $this->commonStatus();

        try {
            return SourceResult::ok($source, ($this->map)());
        } catch (DecodingExceptionInterface|InvalidUpstreamPayloadException|\JsonException $exception) {
            return SourceResult::failed($source, FailureKind::InvalidPayload, $status, cause: FailureCause::of($exception));
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

    /**
     * @return list<ResponseInterface>
     */
    private function uncanceledFirst(): array
    {
        $canceled = array_filter($this->responses, self::isCanceled(...));

        return [...array_diff_key($this->responses, $canceled), ...$canceled];
    }

    private static function isCanceled(ResponseInterface $response): bool
    {
        return true === $response->getInfo(self::CANCELED);
    }

    public static function isSuccessful(int $status): bool
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

    private function commonStatus(): ?int
    {
        $statuses = array_unique(array_map(static fn (ResponseInterface $response): int => $response->getStatusCode(), $this->responses));

        if (1 === \count($statuses)) {
            return array_first($statuses);
        }

        return null;
    }

    /**
     * @return non-empty-list<ResponseInterface>
     */
    public function responses(): array
    {
        return $this->responses;
    }

    public function cancel(): void
    {
        foreach ($this->responses as $response) {
            $response->cancel();
        }
    }

    private function retryAt(ResponseInterface $response): ?\DateTimeImmutable
    {
        $retryAfter = self::retryAfterHeader($response);

        if (null === $retryAfter) {
            return null;
        }

        return RetryAfter::parse($retryAfter, $this->clock->now());
    }

    private static function retryAfterHeader(ResponseInterface $response): ?string
    {
        return $response->getHeaders(false)[RetryAfter::HEADER][0] ?? null;
    }
}
