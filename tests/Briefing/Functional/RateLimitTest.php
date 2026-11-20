<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Functional;

use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Tests\Support\Briefing\BriefingRateLimit;
use App\Tests\Support\Briefing\InteractsWithRateLimit;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\InteractsWithTestServices;
use Monolog\Level;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RateLimitTest extends FunctionalTestCase
{
    use InteractsWithRateLimit;
    use InteractsWithTestServices;

    private const int LONGEST_RETRY_AFTER = BriefingRateLimit::WINDOW_SECONDS + 1;
    private const string NEIGHBOUR_IP = '127.0.0.2';
    private const string FORWARDED_IP = '203.0.113.7';
    private const string IPV6_CLIENT = '2001:db8:1:2::1';
    private const string IPV6_CLIENT_IN_THE_SAME_NETWORK = '2001:db8:1:2:aaaa:bbbb:cccc:dddd';
    private const string IPV6_CLIENT_IN_THE_NEXT_NETWORK = '2001:db8:1:3::1';
    private const string BRIEFING = '/api/briefings/2024-03-15';
    private const string OTHER_DAY_BRIEFING = '/api/briefings/2024-03-16';
    private const string INVALID_DATE_BRIEFING = '/api/briefings/not-a-date';
    private const string INVALID_COORDINATES_BRIEFING = '/api/briefings/2024-03-15?lat=999&lon=0';
    private const string RETRY_AFTER = 'Retry-After';
    private const string CONTENT_TYPE = 'Content-Type';
    private const string REMOTE_ADDR = 'REMOTE_ADDR';

    public function testServesTheLastRequestWithinTheLimit(): void
    {
        // Given
        self::spendBriefingRequests(BriefingRateLimit::LIMIT - 1);
        self::upstream()->respondWith(self::pictureDay());

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING);

        // Then
        self::assertResponseIsSuccessful();
        self::assertSame(0, self::briefingRequestsLeft());
    }

    public function testRejectsARequestOverTheLimitWithRetryAfter(): void
    {
        // Given
        self::spendBriefingRequests(BriefingRateLimit::LIMIT);

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertResponseHeaderSame(self::CONTENT_TYPE, ProblemJsonErrorRenderer::PROBLEM_JSON);
        self::assertRetryAfterIsTheRestOfTheWindow();
        self::assertSame([], self::upstream()->served());
        self::assertSame([Level::Notice], self::loggedExceptionLevels());
        self::assertSame([], self::levelsFrom(Level::Warning));
    }

    public function testSharesTheLimitAcrossDates(): void
    {
        // Given
        self::spendBriefingRequests(BriefingRateLimit::LIMIT);

        // When
        $this->client->request(Request::METHOD_GET, self::OTHER_DAY_BRIEFING);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testKeepsASeparateLimitForEveryIpv4Address(): void
    {
        // Given
        self::spendBriefingRequests(BriefingRateLimit::LIMIT, self::NEIGHBOUR_IP);
        self::upstream()->respondWith(self::pictureDay());

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING);

        // Then
        self::assertResponseIsSuccessful();
    }

    public function testSharesTheLimitAcrossAnIpv6Network(): void
    {
        // Given
        self::spendBriefingRequests(BriefingRateLimit::LIMIT, self::IPV6_CLIENT);

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING, server: [self::REMOTE_ADDR => self::IPV6_CLIENT_IN_THE_SAME_NETWORK]);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testKeepsASeparateLimitForTheNextIpv6Network(): void
    {
        // Given
        self::spendBriefingRequests(BriefingRateLimit::LIMIT, self::IPV6_CLIENT);
        self::upstream()->respondWith(self::pictureDay());

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING, server: [self::REMOTE_ADDR => self::IPV6_CLIENT_IN_THE_NEXT_NETWORK]);

        // Then
        self::assertResponseIsSuccessful();
    }

    public function testIgnoresXForwardedForWhenChoosingTheClient(): void
    {
        // Given
        self::spendBriefingRequests(BriefingRateLimit::LIMIT);

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING, server: ['HTTP_X_FORWARDED_FOR' => self::FORWARDED_IP]);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testCountsHeadRequests(): void
    {
        // Given
        self::spendBriefingRequests(BriefingRateLimit::LIMIT);

        // When
        $this->client->request(Request::METHOD_HEAD, self::BRIEFING);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testDoesNotCountARequestWithAnInvalidDate(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::INVALID_DATE_BRIEFING);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(BriefingRateLimit::LIMIT, self::briefingRequestsLeft());
    }

    public function testCountsARequestWithInvalidCoordinates(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::INVALID_COORDINATES_BRIEFING);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(BriefingRateLimit::LIMIT - 1, self::briefingRequestsLeft());
    }

    private function assertRetryAfterIsTheRestOfTheWindow(): void
    {
        $retryAfter = (string) $this->client->getResponse()->headers->get(self::RETRY_AFTER);
        self::assertMatchesRegularExpression('/^\d+$/', $retryAfter);
        self::assertGreaterThanOrEqual(BriefingRateLimit::WINDOW_SECONDS, (int) $retryAfter);
        self::assertLessThanOrEqual(self::LONGEST_RETRY_AFTER, (int) $retryAfter);
    }

    private static function upstream(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }

    private static function pictureDay(): UpstreamMock
    {
        return UpstreamMock::create()
            ->onThisDayFrom('03-15')
            ->archiveOn('2024-03-15')
            ->apod()->fixture('apod/passthrough-2024-03-15.json');
    }
}
