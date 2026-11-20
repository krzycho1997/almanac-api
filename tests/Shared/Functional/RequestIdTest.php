<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Shared\Logging\RequestIdProcessor;
use App\Shared\RequestId\RequestIdHolder;
use App\Shared\RequestId\RequestIdListener;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\RequestIds;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;

final class RequestIdTest extends FunctionalTestCase
{
    private const string UNKNOWN_ROUTE = '/api/unknown';

    public function testKeepsTheRequestIdFromNginxInTheResponseAndEveryLogRecord(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::UNKNOWN_ROUTE, server: ['HTTP_X_REQUEST_ID' => RequestIds::FROM_NGINX]);

        // Then
        self::assertResponseHeaderSame(RequestIdListener::HEADER, RequestIds::FROM_NGINX);
        self::assertSame([RequestIds::FROM_NGINX], self::loggedRequestIds());
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('requestsWithoutAUsableRequestId')]
    public function testGeneratesOneRequestIdForTheResponseAndTheLogs(array $server): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::UNKNOWN_ROUTE, server: $server);

        // Then
        $requestId = (string) $this->client->getResponse()->headers->get(RequestIdListener::HEADER);
        self::assertMatchesRegularExpression(RequestIds::GENERATED, $requestId);
        self::assertSame([$requestId], self::loggedRequestIds());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function requestsWithoutAUsableRequestId(): iterable
    {
        yield 'without nginx' => [[]];
        yield 'malformed' => [['HTTP_X_REQUEST_ID' => 'not valid']];
    }

    public function testForgetsTheRequestIdWhenServicesAreReset(): void
    {
        // Given
        $this->client->request(Request::METHOD_GET, self::UNKNOWN_ROUTE);
        $resetter = self::getContainer()->get('services_resetter');

        // When
        $resetter->reset();

        // Then
        self::assertNull(self::getContainer()->get(RequestIdHolder::class)->get());
    }

    /**
     * @return list<mixed>
     */
    private static function loggedRequestIds(): array
    {
        $records = self::logHandler()->getRecords();
        self::assertNotEmpty($records);

        return array_values(array_unique(array_map(static fn (LogRecord $record): mixed => $record->extra[RequestIdProcessor::REQUEST_ID] ?? null, $records), \SORT_REGULAR));
    }
}
