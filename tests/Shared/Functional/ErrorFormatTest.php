<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Tests\Support\FunctionalTestCase;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ErrorFormatTest extends FunctionalTestCase
{
    private const string CONTENT_TYPE = 'Content-Type';
    private const string HEALTH = '/api/health';
    private const string PROBLEM_TYPE = 'https://tools.ietf.org/html/rfc2616#section-10';
    private const string PROBLEM_TITLE = 'An error occurred';
    private const string REQUEST_CHANNEL = 'request';
    private const string EXCEPTION = 'exception';

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
        self::assertResponseHeaderSame(self::CONTENT_TYPE, ProblemJsonErrorRenderer::PROBLEM_JSON);
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
        yield 'method not allowed' => [Request::METHOD_POST, self::HEALTH, [], null, Response::HTTP_METHOD_NOT_ALLOWED, 'Method Not Allowed'];
        yield 'malformed JSON' => [Request::METHOD_POST, '/_test/payload', ['CONTENT_TYPE' => 'application/json'], '{"name":', Response::HTTP_BAD_REQUEST, 'Bad Request'];
        yield 'unsupported content type' => [Request::METHOD_POST, '/_test/payload', ['CONTENT_TYPE' => 'text/plain'], 'name', Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'Unsupported Media Type'];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('clientErrors')]
    public function testLogsClientErrorsAtNotice(string $method, string $uri, array $server, ?string $content): void
    {
        // When
        $this->client->request($method, $uri, server: $server, content: $content);

        // Then
        self::assertSame([Level::Notice], self::loggedExceptionLevels());
        self::assertSame([], self::levelsFrom(Level::Warning));
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, ?string}>
     */
    public static function clientErrors(): iterable
    {
        yield 'unknown route' => [Request::METHOD_GET, '/api/unknown', [], null];
        yield 'method not allowed' => [Request::METHOD_POST, self::HEALTH, [], null];
        yield 'malformed JSON' => [Request::METHOD_POST, '/_test/payload', ['CONTENT_TYPE' => 'application/json'], '{"name":'];
        yield 'unsupported content type' => [Request::METHOD_POST, '/_test/payload', ['CONTENT_TYPE' => 'text/plain'], 'name'];
        yield 'forbidden' => [Request::METHOD_GET, '/_test/http-error/'.Response::HTTP_FORBIDDEN, [], null];
        yield 'client error without its own exception class' => [Request::METHOD_GET, '/_test/http-error/'.Response::HTTP_I_AM_A_TEAPOT, [], null];
    }

    public function testLogsServerErrorsAtCritical(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, '/_test/http-error/'.Response::HTTP_SERVICE_UNAVAILABLE);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame([Level::Critical], self::loggedExceptionLevels());
    }

    public function testKeepsAnExplicitlyConfiguredLogLevel(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, '/_test/explicitly-logged-conflict');

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame([Level::Warning], self::loggedExceptionLevels());
    }

    public function testKeepsTheAllowHeaderOnMethodNotAllowed(): void
    {
        // When
        $this->client->request(Request::METHOD_DELETE, self::HEALTH);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        self::assertResponseHeaderSame(self::CONTENT_TYPE, ProblemJsonErrorRenderer::PROBLEM_JSON);
        self::assertResponseHeaderSame('Allow', Request::METHOD_GET);
    }

    public function testKeepsJsonForSuccessfulResponses(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::HEALTH);

        // Then
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame(self::CONTENT_TYPE, ProblemJsonErrorRenderer::JSON);
    }

    /**
     * @return list<Level>
     */
    private static function loggedExceptionLevels(): array
    {
        $records = array_filter(
            self::logHandler()->getRecords(),
            static fn (LogRecord $record): bool => self::REQUEST_CHANNEL === $record->channel && isset($record->context[self::EXCEPTION]),
        );

        return array_values(array_map(static fn (LogRecord $record): Level => $record->level, $records));
    }

    /**
     * @return list<Level>
     */
    private static function levelsFrom(Level $minimum): array
    {
        $levels = array_map(static fn (LogRecord $record): Level => $record->level, self::logHandler()->getRecords());

        return array_values(array_filter($levels, static fn (Level $level): bool => $minimum->includes($level)));
    }
}
