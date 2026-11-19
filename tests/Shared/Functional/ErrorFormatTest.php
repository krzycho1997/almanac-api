<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ErrorFormatTest extends FunctionalTestCase
{
    private const string PROBLEM_JSON = 'application/problem+json';
    private const string PROBLEM_TYPE = 'https://tools.ietf.org/html/rfc2616#section-10';
    private const string PROBLEM_TITLE = 'An error occurred';

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('errors')]
    public function testRendersErrorsAsProblemDetailsWithoutInternals(string $method, string $uri, array $server, ?string $content, int $status, string $detail): void
    {
        // Given
        self::ensureKernelShutdown();
        $client = self::createClient(['debug' => false]);

        // When
        $client->request($method, $uri, server: $server, content: $content);

        // Then
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Type', self::PROBLEM_JSON);
        self::assertJsonStringEqualsJsonString(
            (string) json_encode([
                'type' => self::PROBLEM_TYPE,
                'title' => self::PROBLEM_TITLE,
                'status' => $status,
                'detail' => $detail,
            ]),
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, ?string, int, string}>
     */
    public static function errors(): iterable
    {
        yield 'unknown route' => [Request::METHOD_GET, '/api/unknown', [], null, Response::HTTP_NOT_FOUND, 'Not Found'];
        yield 'unknown route asked for as HTML' => [Request::METHOD_GET, '/api/unknown', ['HTTP_ACCEPT' => 'text/html'], null, Response::HTTP_NOT_FOUND, 'Not Found'];
        yield 'method not allowed' => [Request::METHOD_POST, '/api/health', [], null, Response::HTTP_METHOD_NOT_ALLOWED, 'Method Not Allowed'];
        yield 'malformed JSON' => [Request::METHOD_POST, '/_test/payload', ['CONTENT_TYPE' => 'application/json'], '{"name":', Response::HTTP_BAD_REQUEST, 'Bad Request'];
        yield 'unsupported content type' => [Request::METHOD_POST, '/_test/payload', ['CONTENT_TYPE' => 'text/plain'], 'name', Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'Unsupported Media Type'];
    }

    public function testKeepsTheAllowHeaderOnMethodNotAllowed(): void
    {
        // When
        $this->client->request(Request::METHOD_DELETE, '/api/health');

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        self::assertResponseHeaderSame('Content-Type', self::PROBLEM_JSON);
        self::assertResponseHeaderSame('Allow', Request::METHOD_GET);
    }

    public function testKeepsJsonForSuccessfulResponses(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, '/api/health');

        // Then
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }
}
