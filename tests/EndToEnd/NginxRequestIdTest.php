<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Shared\RequestId\RequestIdListener;
use App\Tests\Support\EndToEnd\HttpProbe;
use App\Tests\Support\RequestIds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class NginxRequestIdTest extends TestCase
{
    private const string HEALTH = '/api/health';

    public function testAssignsARequestIdWhenTheClientSendsNone(): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get(self::HEALTH);

        // Then
        self::assertSame(Response::HTTP_OK, $response->status);
        self::assertMatchesRegularExpression(RequestIds::GENERATED, self::onlyRequestId($response->headerValues(RequestIdListener::HEADER)));
    }

    public function testKeepsAWellFormedClientRequestId(): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get(self::HEALTH, [RequestIdListener::HEADER => RequestIds::FROM_CLIENT]);

        // Then
        self::assertSame([RequestIds::FROM_CLIENT], $response->headerValues(RequestIdListener::HEADER));
    }

    #[DataProvider('malformedRequestIds')]
    public function testReplacesAMalformedClientRequestId(string $requestId): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get(self::HEALTH, [RequestIdListener::HEADER => $requestId]);

        // Then
        self::assertMatchesRegularExpression(RequestIds::GENERATED, self::onlyRequestId($response->headerValues(RequestIdListener::HEADER)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedRequestIds(): iterable
    {
        yield 'with a space' => ['not valid'];
        yield '65 characters' => [str_repeat('a', 65)];
        yield 'with a quote' => ['x"evil'];
        yield 'empty' => [''];
    }

    #[DataProvider('errorResponses')]
    public function testReturnsExactlyOneRequestIdOnErrors(string $path, int $status): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get($path);

        // Then
        self::assertSame($status, $response->status);
        self::assertMatchesRegularExpression(RequestIds::GENERATED, self::onlyRequestId($response->headerValues(RequestIdListener::HEADER)));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function errorResponses(): iterable
    {
        yield 'not found by nginx' => ['/missing.php', Response::HTTP_NOT_FOUND];
        yield 'not found by Symfony' => ['/api/missing', Response::HTTP_NOT_FOUND];
    }

    /**
     * @param list<string> $requestIds
     */
    private static function onlyRequestId(array $requestIds): string
    {
        self::assertCount(1, $requestIds);

        return $requestIds[0];
    }
}
