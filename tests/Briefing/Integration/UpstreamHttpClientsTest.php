<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\InteractsWithTestServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class UpstreamHttpClientsTest extends KernelTestCase
{
    use InteractsWithTestServices;

    private const string NASA = 'test.nasa.client';
    private const string WIKIPEDIA = 'test.wikipedia.client';
    private const string OPEN_METEO = 'test.open_meteo.client';
    private const string OPEN_METEO_ARCHIVE = 'test.open_meteo_archive.client';
    private const string USER_AGENT = 'almanac-api/1.0 (+https://github.com/krzycho1997/almanac-api)';
    private const string NASA_KEY_HEADER = 'X-Api-Key: DEMO_KEY';
    private const string PATH = 'planetary/apod';

    #[DataProvider('clients')]
    public function testSendsRequestsToItsUpstreamAsAlmanac(string $client, UpstreamMock $scenario, string $path, string $expectedUrl, bool $sendsNasaKey): void
    {
        // Given
        self::factory()->respondWith($scenario);

        // When
        self::client($client)->request(Request::METHOD_GET, $path)->getStatusCode();

        // Then
        $request = self::onlyServedRequest();
        self::assertSame($expectedUrl, $request->getRequestUrl());
        self::assertContains('User-Agent: '.self::USER_AGENT, self::requestHeaders($request));
        self::assertSame($sendsNasaKey, \in_array(self::NASA_KEY_HEADER, self::requestHeaders($request), true));
    }

    /**
     * @return iterable<string, array{string, UpstreamMock, string, string, bool}>
     */
    public static function clients(): iterable
    {
        yield 'NASA' => [self::NASA, UpstreamMock::create()->apod(), self::PATH, 'https://api.nasa.gov/planetary/apod', true];
        yield 'Wikipedia' => [self::WIKIPEDIA, UpstreamMock::create()->onThisDay(), 'feed/onthisday/all/11/20', 'https://en.wikipedia.org/api/rest_v1/feed/onthisday/all/11/20', false];
        yield 'Open-Meteo forecast' => [self::OPEN_METEO, UpstreamMock::create()->forecast(), 'v1/forecast', 'https://api.open-meteo.com/v1/forecast', false];
        yield 'Open-Meteo archive' => [self::OPEN_METEO_ARCHIVE, UpstreamMock::create()->archive(), 'v1/archive', 'https://archive-api.open-meteo.com/v1/archive', false];
    }

    #[DataProvider('clientNames')]
    public function testLimitsTimeAndFollowsNoRedirects(string $client, UpstreamMock $scenario): void
    {
        // Given
        self::factory()->respondWith($scenario);

        // When
        self::client($client)->request(Request::METHOD_GET, self::PATH)->getStatusCode();

        // Then
        $options = self::onlyServedRequest()->getRequestOptions();
        self::assertSame(2.0, $options['timeout']);
        self::assertSame(3.0, $options['max_duration']);
        self::assertSame(0, $options['max_redirects']);
        self::assertSame(1.0, $options['max_connect_duration']);
    }

    /**
     * @return iterable<string, array{string, UpstreamMock}>
     */
    public static function clientNames(): iterable
    {
        yield 'NASA' => [self::NASA, UpstreamMock::create()->apod()];
        yield 'Wikipedia' => [self::WIKIPEDIA, UpstreamMock::create()->onThisDay()];
        yield 'Open-Meteo forecast' => [self::OPEN_METEO, UpstreamMock::create()->forecast()];
        yield 'Open-Meteo archive' => [self::OPEN_METEO_ARCHIVE, UpstreamMock::create()->archive()];
    }

    #[DataProvider('retries')]
    public function testRetriesOnlyTransientFailuresOnce(UpstreamMock $scenario, int $expectedStatus, int $expectedRequests): void
    {
        // Given
        self::factory()->respondWith($scenario);

        // When
        $status = self::client(self::NASA)->request(Request::METHOD_GET, self::PATH)->getStatusCode();

        // Then
        self::assertSame($expectedStatus, $status);
        self::assertCount($expectedRequests, self::factory()->served());
    }

    /**
     * @return iterable<string, array{UpstreamMock, int, int}>
     */
    public static function retries(): iterable
    {
        yield 'internal server error, then ok' => [UpstreamMock::create()->apod()->status(Response::HTTP_INTERNAL_SERVER_ERROR)->apod(), Response::HTTP_OK, 2];
        yield 'service unavailable, then ok' => [UpstreamMock::create()->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod(), Response::HTTP_OK, 2];
        yield 'gateway timeout, then ok' => [UpstreamMock::create()->apod()->status(Response::HTTP_GATEWAY_TIMEOUT)->apod(), Response::HTTP_OK, 2];
        yield 'request timeout' => [UpstreamMock::create()->apod()->status(Response::HTTP_REQUEST_TIMEOUT)->apod(), Response::HTTP_REQUEST_TIMEOUT, 1];
        yield 'not implemented' => [UpstreamMock::create()->apod()->status(Response::HTTP_NOT_IMPLEMENTED)->apod(), Response::HTTP_NOT_IMPLEMENTED, 1];
        yield 'bad gateway twice' => [UpstreamMock::create()->apod()->status(Response::HTTP_BAD_GATEWAY)->apod()->status(Response::HTTP_BAD_GATEWAY)->apod(), Response::HTTP_BAD_GATEWAY, 2];
        yield 'too many requests without Retry-After' => [UpstreamMock::create()->apod()->status(Response::HTTP_TOO_MANY_REQUESTS)->apod(), Response::HTTP_TOO_MANY_REQUESTS, 1];
        yield 'too many requests' => [UpstreamMock::create()->apod()->status(Response::HTTP_TOO_MANY_REQUESTS)->retryAfter(3600)->apod(), Response::HTTP_TOO_MANY_REQUESTS, 1];
        yield 'service unavailable asking to wait' => [UpstreamMock::create()->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->retryAfter(6)->apod(), Response::HTTP_SERVICE_UNAVAILABLE, 1];
        yield 'not found' => [UpstreamMock::create()->apod()->status(Response::HTTP_NOT_FOUND)->apod(), Response::HTTP_NOT_FOUND, 1];
        yield 'moved permanently' => [UpstreamMock::create()->apod()->status(Response::HTTP_MOVED_PERMANENTLY)->apod(), Response::HTTP_MOVED_PERMANENTLY, 1];
    }

    public function testRetriesANetworkErrorOnce(): void
    {
        // Given
        self::factory()->respondWith(UpstreamMock::create()->apod()->networkError()->apod());

        // When
        $status = self::client(self::NASA)->request(Request::METHOD_GET, self::PATH)->getStatusCode();

        // Then
        self::assertSame(Response::HTTP_OK, $status);
        self::assertCount(2, self::factory()->served());
    }

    public function testDoesNotRetryABodyThatStallsAfterTheHeaders(): void
    {
        // Given
        self::factory()->respondWith(UpstreamMock::create()->apod()->timeout()->apod());
        $response = self::client(self::NASA)->request(Request::METHOD_GET, self::PATH);

        try {
            // When
            $response->getContent();
            self::fail('A stalled body must surface as a transport error.');
        } catch (TransportExceptionInterface) {
            // Then
            self::assertCount(1, self::factory()->served());
        }
    }

    public function testRefusesARequestTheScenarioDoesNotPlan(): void
    {
        // Given
        self::factory()->respondWith(UpstreamMock::create()->apod());

        // Then / When
        $this->expectException(\LogicException::class);
        self::client(self::WIKIPEDIA)->request(Request::METHOD_GET, 'feed/onthisday/all/11/20');
    }

    private static function factory(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }

    private static function client(string $id): HttpClientInterface
    {
        $client = self::getContainer()->get($id);
        self::assertInstanceOf(HttpClientInterface::class, $client);

        return $client;
    }

    private static function onlyServedRequest(): MockResponse
    {
        $served = self::factory()->served();
        self::assertCount(1, $served);

        return $served[0];
    }

    /**
     * @return list<string>
     */
    private static function requestHeaders(MockResponse $request): array
    {
        $headers = $request->getRequestOptions()['headers'] ?? [];
        self::assertIsArray($headers);

        return array_values(array_filter($headers, is_string(...)));
    }
}
