<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Recording;

use App\Tests\Support\Briefing\Recording\ShortRetryAfter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShortRetryAfterTest extends TestCase
{
    private const string URL = 'https://upstream.test/resource';

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('firstAnswers')]
    public function testRetriesOnceOnlyAThrottledAnswerWithAShortWait(int $status, array $headers, int $expected): void
    {
        // Given
        $client = new RetryableHttpClient(new MockHttpClient([new MockResponse('{}', ['http_code' => $status, 'response_headers' => $headers]), new MockResponse('{}')]), new ShortRetryAfter(), 1);

        // When
        $answered = $client->request(Request::METHOD_GET, self::URL)->getStatusCode();

        // Then
        self::assertSame($expected, $answered);
    }

    /**
     * @return iterable<string, array{int, array<string, string>, int}>
     */
    public static function firstAnswers(): iterable
    {
        yield 'rate limited, retry now' => [Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '0'], Response::HTTP_OK];
        yield 'unavailable, retry now' => [Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '0'], Response::HTTP_OK];
        yield 'rate limited for longer than ten seconds' => [Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '11'], Response::HTTP_TOO_MANY_REQUESTS];
        yield 'rate limited until a date' => [Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => 'Fri, 20 Nov 2026 18:00:00 GMT'], Response::HTTP_TOO_MANY_REQUESTS];
        yield 'rate limited without a wait' => [Response::HTTP_TOO_MANY_REQUESTS, [], Response::HTTP_TOO_MANY_REQUESTS];
        yield 'server error' => [Response::HTTP_INTERNAL_SERVER_ERROR, ['Retry-After' => '0'], Response::HTTP_INTERNAL_SERVER_ERROR];
    }
}
