<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\RequestId\RequestIdHolder;
use App\Shared\RequestId\RequestIdListener;
use App\Tests\Support\RequestIds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestIdListenerTest extends TestCase
{
    #[DataProvider('acceptedRequestIds')]
    public function testKeepsAWellFormedRequestId(string $requestId): void
    {
        // Given
        $holder = new RequestIdHolder();

        // When
        new RequestIdListener($holder)->assign(self::requestEvent([RequestIdListener::HEADER => $requestId], HttpKernelInterface::MAIN_REQUEST));

        // Then
        self::assertSame($requestId, $holder->get());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedRequestIds(): iterable
    {
        yield 'nginx id' => [RequestIds::FROM_NGINX];
        yield 'UUID' => ['0b5e4a52-6a8f-4d4b-9a0c-3e1f2d7c8b90'];
        yield 'letters, digits, dot, dash and underscore' => [RequestIds::FROM_CLIENT];
        yield '64 characters' => [str_repeat('a', 64)];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('replacedRequestIds')]
    public function testGeneratesARequestIdInsteadOfAMissingOrMalformedOne(array $headers): void
    {
        // Given
        $holder = new RequestIdHolder();

        // When
        new RequestIdListener($holder)->assign(self::requestEvent($headers, HttpKernelInterface::MAIN_REQUEST));

        // Then
        self::assertMatchesRegularExpression(RequestIds::GENERATED, (string) $holder->get());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function replacedRequestIds(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [[RequestIdListener::HEADER => '']];
        yield 'with a space' => [[RequestIdListener::HEADER => 'not valid']];
        yield 'with a log line break' => [[RequestIdListener::HEADER => "a\r\nevil"]];
        yield 'with a trailing line break' => [[RequestIdListener::HEADER => "abc\n"]];
        yield 'with a quote' => [[RequestIdListener::HEADER => 'x"evil']];
        yield '65 characters' => [[RequestIdListener::HEADER => str_repeat('a', 65)]];
        yield 'non-ASCII' => [[RequestIdListener::HEADER => 'zażółć']];
        yield 'two joined values' => [[RequestIdListener::HEADER => 'first-1, second-2']];
    }

    public function testIgnoresSubRequests(): void
    {
        // Given
        $holder = new RequestIdHolder();

        // When
        new RequestIdListener($holder)->assign(self::requestEvent([RequestIdListener::HEADER => 'from-sub-request'], HttpKernelInterface::SUB_REQUEST));

        // Then
        self::assertNull($holder->get());
    }

    public function testReturnsTheRequestIdOnTheMainResponse(): void
    {
        // Given
        $holder = new RequestIdHolder();
        $holder->set(RequestIds::FROM_CLIENT);
        $response = new Response();

        // When
        new RequestIdListener($holder)->expose(self::responseEvent($response, HttpKernelInterface::MAIN_REQUEST));

        // Then
        self::assertSame(RequestIds::FROM_CLIENT, $response->headers->get(RequestIdListener::HEADER));
    }

    public function testLeavesSubResponsesAlone(): void
    {
        // Given
        $holder = new RequestIdHolder();
        $holder->set(RequestIds::FROM_CLIENT);
        $response = new Response();

        // When
        new RequestIdListener($holder)->expose(self::responseEvent($response, HttpKernelInterface::SUB_REQUEST));

        // Then
        self::assertFalse($response->headers->has(RequestIdListener::HEADER));
    }

    public function testAddsNoHeaderWithoutARequestId(): void
    {
        // Given
        $response = new Response();

        // When
        new RequestIdListener(new RequestIdHolder())->expose(self::responseEvent($response, HttpKernelInterface::MAIN_REQUEST));

        // Then
        self::assertFalse($response->headers->has(RequestIdListener::HEADER));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function requestEvent(array $headers, int $requestType): RequestEvent
    {
        $request = new Request();
        $request->headers->add($headers);

        return new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $requestType);
    }

    private static function responseEvent(Response $response, int $requestType): ResponseEvent
    {
        return new ResponseEvent(self::createStub(HttpKernelInterface::class), new Request(), $requestType, $response);
    }
}
