<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\FailureKind;
use App\Briefing\Model\SourcePayload;
use App\Briefing\Source\PendingFetch;
use App\Briefing\SourceName;
use App\Briefing\SourceStatus;
use App\Tests\Support\Briefing\FakePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class PendingFetchTest extends TestCase
{
    private const string NOW = '2026-11-20 12:00:00';
    private const string URL = 'https://upstream.test/resource';
    private const string BODY = '{"title":"Pillars of Creation"}';

    public function testMapsASuccessfulResponseToItsPayload(): void
    {
        // Given
        $fetch = self::fetch(new MockResponse(self::BODY));

        // When
        $result = $fetch->resolve(SourceName::Apod);

        // Then
        self::assertSame(SourceName::Apod, $result->source);
        self::assertSame(SourceStatus::Ok, $result->status);
        self::assertEquals(new FakePayload(['title' => 'Pillars of Creation']), $result->payload);
        self::assertNull($result->failureKind);
    }

    #[DataProvider('failedStatuses')]
    public function testMapsAFailedStatusToItsKind(int $status, FailureKind $kind): void
    {
        // Given
        $fetch = self::fetch(new MockResponse(self::BODY, ['http_code' => $status]));

        // When
        $result = $fetch->resolve(SourceName::Apod);

        // Then
        self::assertSame(SourceStatus::Failed, $result->status);
        self::assertSame($kind, $result->failureKind);
        self::assertSame($status, $result->httpStatus);
        self::assertNull($result->payload);
        self::assertNull($result->retryAt);
    }

    /**
     * @return iterable<string, array{int, FailureKind}>
     */
    public static function failedStatuses(): iterable
    {
        yield 'internal server error' => [Response::HTTP_INTERNAL_SERVER_ERROR, FailureKind::Unavailable];
        yield 'bad gateway' => [Response::HTTP_BAD_GATEWAY, FailureKind::Unavailable];
        yield 'service unavailable' => [Response::HTTP_SERVICE_UNAVAILABLE, FailureKind::Unavailable];
        yield 'request timeout' => [Response::HTTP_REQUEST_TIMEOUT, FailureKind::Unavailable];
        yield 'unauthorized' => [Response::HTTP_UNAUTHORIZED, FailureKind::Misconfigured];
        yield 'forbidden' => [Response::HTTP_FORBIDDEN, FailureKind::Misconfigured];
        yield 'multiple choices' => [Response::HTTP_MULTIPLE_CHOICES, FailureKind::Misconfigured];
        yield 'moved permanently' => [Response::HTTP_MOVED_PERMANENTLY, FailureKind::Misconfigured];
        yield 'last redirect status' => [399, FailureKind::Misconfigured];
        yield 'bad request' => [Response::HTTP_BAD_REQUEST, FailureKind::Rejected];
        yield 'not found' => [Response::HTTP_NOT_FOUND, FailureKind::Rejected];
        yield 'last client error' => [499, FailureKind::Rejected];
        yield 'too many requests without Retry-After' => [Response::HTTP_TOO_MANY_REQUESTS, FailureKind::RateLimited];
    }

    #[DataProvider('retryAfterHeaders')]
    public function testTurnsRetryAfterIntoATime(string $retryAfter, ?string $expectedRetryAt): void
    {
        // Given
        $fetch = self::fetch(new MockResponse('', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS, 'response_headers' => ['Retry-After' => $retryAfter]]));

        // When
        $result = $fetch->resolve(SourceName::Apod);

        // Then
        self::assertSame(FailureKind::RateLimited, $result->failureKind);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $result->httpStatus);
        self::assertSame($expectedRetryAt, $result->retryAt?->format(\DateTimeInterface::ATOM));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function retryAfterHeaders(): iterable
    {
        yield 'seconds' => ['3600', '2026-11-20T13:00:00+00:00'];
        yield 'zero seconds' => ['0', '2026-11-20T12:00:00+00:00'];
        yield 'HTTP date' => ['Fri, 20 Nov 2026 14:30:00 GMT', '2026-11-20T14:30:00+00:00'];
        yield 'not a date' => ['soon', null];
    }

    public function testReportsANetworkFailureAsUnavailable(): void
    {
        // Given
        $fetch = self::fetch(new MockResponse('', ['error' => 'Connection refused']));

        // When
        $result = $fetch->resolve(SourceName::Weather);

        // Then
        self::assertSame(FailureKind::Unavailable, $result->failureKind);
        self::assertNull($result->httpStatus);
    }

    public function testReportsATimeoutWhileReadingTheBodyAsUnavailable(): void
    {
        // Given
        $fetch = self::fetch(new MockResponse(['{"title":', new \RuntimeException('Idle timeout reached')]));

        // When
        $result = $fetch->resolve(SourceName::Weather);

        // Then
        self::assertSame(FailureKind::Unavailable, $result->failureKind);
    }

    #[DataProvider('invalidPayloads')]
    public function testReportsAnInvalidPayload(string $body, \Closure $map): void
    {
        // Given
        $fetch = self::fetch(new MockResponse($body), $map);

        // When
        $result = $fetch->resolve(SourceName::OnThisDay);

        // Then
        self::assertSame(FailureKind::InvalidPayload, $result->failureKind);
        self::assertSame(Response::HTTP_OK, $result->httpStatus);
    }

    /**
     * @return iterable<string, array{string, \Closure(ResponseInterface): SourcePayload}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'malformed JSON' => ['{"title":', self::decodedPayload(...)];
        yield 'JSON the mapper rejects' => [self::BODY, static fn (ResponseInterface $response): SourcePayload => throw InvalidUpstreamPayloadException::because('missing url')];
        yield 'JSON decoded by the mapper itself' => ['{"title":', static fn (ResponseInterface $response): SourcePayload => new FakePayload((array) json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR))];
    }

    /**
     * @param (\Closure(ResponseInterface): SourcePayload)|null $map
     */
    private static function fetch(MockResponse $mockResponse, ?\Closure $map = null): PendingFetch
    {
        $response = new MockHttpClient($mockResponse)->request('GET', self::URL);

        return new PendingFetch($response, $map ?? self::decodedPayload(...), new MockClock(self::NOW));
    }

    private static function decodedPayload(ResponseInterface $response): SourcePayload
    {
        return new FakePayload($response->toArray());
    }
}
