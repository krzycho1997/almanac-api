<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\OpenApi;

use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Shared\RequestId\RequestIdListener;
use App\Tests\Support\OpenApi\OpenApiContract;
use App\Tests\Support\OpenApi\OpenApiDocument;
use App\Tests\Support\RequestIds;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OpenApiContractTest extends TestCase
{
    private const string DOCUMENT = __DIR__.'/../../../Fixtures/openapi/contract.yaml';
    private const string ITEMS = '/items';
    private const string UNKNOWN = '/unknown';
    private const string JSON = 'application/json';
    private const string CONTENT_TYPE = 'Content-Type';
    private const string VALID_ITEM = '{"name":"Pillars"}';
    private const string INVALID_ITEM = '{"title":"Pillars"}';
    private const string ITEM = '/items/1';
    private const string NOTES = '/notes';
    private const string ITEM_PATH = '/items/{id}';
    private const string PROBLEM = '{"type":"about:blank","title":"An error occurred","status":%d,"detail":"An error occurred"}';

    #[DataProvider('honoured')]
    public function testAcceptsAnExchangeThatMatchesTheDocument(Request $request, Response $response, ?string $path): void
    {
        // Given
        $contract = new OpenApiContract(OpenApiDocument::fromFile(self::DOCUMENT));

        // When
        $contract->assertHonoured($request, $response, $path);

        // Then
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{Request, Response, ?string}>
     */
    public static function honoured(): iterable
    {
        yield 'documented operation' => [self::post(self::VALID_ITEM), self::json(self::VALID_ITEM, Response::HTTP_CREATED), self::ITEMS];
        yield 'invalid request rejected with a documented error' => [self::post(self::INVALID_ITEM), self::problem(Response::HTTP_UNPROCESSABLE_ENTITY), self::ITEMS];
        yield 'unknown route answered with problem details' => [Request::create(self::UNKNOWN), self::problem(Response::HTTP_NOT_FOUND), null];
        yield 'undocumented method answered with problem details' => [Request::create(self::ITEMS), self::problem(Response::HTTP_METHOD_NOT_ALLOWED), self::ITEMS];
        yield 'HEAD checked against the documented GET' => [Request::create(self::ITEM, Request::METHOD_HEAD), self::empty(Response::HTTP_OK), self::ITEM_PATH];
        yield 'error documented by its status range' => [Request::create(self::ITEM, Request::METHOD_DELETE), self::problem(Response::HTTP_CONFLICT), self::ITEM_PATH];
        yield 'error documented by the default response' => [Request::create(self::NOTES), self::problem(Response::HTTP_SERVICE_UNAVAILABLE), self::NOTES];
        yield 'documented response without content' => [Request::create(self::ITEM, Request::METHOD_DELETE), new Response(status: Response::HTTP_NO_CONTENT), self::ITEM_PATH];
    }

    #[DataProvider('broken')]
    public function testFailsAnExchangeThatBreaksTheDocument(Request $request, Response $response, ?string $path): void
    {
        // Given
        $contract = new OpenApiContract(OpenApiDocument::fromFile(self::DOCUMENT));

        // When
        $failure = self::failureOf(static fn () => $contract->assertHonoured($request, $response, $path));

        // Then
        self::assertInstanceOf(AssertionFailedError::class, $failure);
    }

    /**
     * @return iterable<string, array{Request, Response, ?string}>
     */
    public static function broken(): iterable
    {
        yield 'undocumented field in the response' => [self::post(self::VALID_ITEM), self::json('{"name":"Pillars","id":1}', Response::HTTP_CREATED), self::ITEMS];
        yield 'undocumented status' => [self::post(self::VALID_ITEM), self::problem(Response::HTTP_INTERNAL_SERVER_ERROR), self::ITEMS];
        yield 'invalid request accepted' => [self::post(self::INVALID_ITEM), self::json(self::VALID_ITEM, Response::HTTP_CREATED), self::ITEMS];
        yield 'unknown route answered with JSON' => [Request::create(self::UNKNOWN), self::json(\sprintf(self::PROBLEM, Response::HTTP_NOT_FOUND), Response::HTTP_NOT_FOUND), null];
        yield 'unknown route answered with a successful problem' => [Request::create(self::UNKNOWN), self::problem(Response::HTTP_OK), null];
        yield 'problem details with an undocumented field' => [Request::create(self::UNKNOWN), self::problem(Response::HTTP_NOT_FOUND, '{"type":"about:blank","title":"Not Found","status":404,"detail":"Not Found","trace":[]}'), null];
        yield 'problem details without a request id' => [Request::create(self::UNKNOWN), self::problemWithoutRequestId(), null];
        yield 'problem status other than the HTTP status' => [Request::create(self::UNKNOWN), self::problem(Response::HTTP_NOT_FOUND, \sprintf(self::PROBLEM, Response::HTTP_INTERNAL_SERVER_ERROR)), null];
        yield 'documented problem status other than the HTTP status' => [self::post(self::INVALID_ITEM), self::problem(Response::HTTP_UNPROCESSABLE_ENTITY, \sprintf(self::PROBLEM, Response::HTTP_BAD_REQUEST)), self::ITEMS];
        yield 'HEAD without a documented header' => [Request::create(self::ITEM, Request::METHOD_HEAD), self::withoutRequestId(self::empty(Response::HTTP_OK)), self::ITEM_PATH];
        yield 'HEAD with an invalid header' => [Request::create(self::ITEM, Request::METHOD_HEAD), self::withRequestId(self::empty(Response::HTTP_OK), 'not a request id!'), self::ITEM_PATH];
        yield 'problem details with an invalid request id' => [Request::create(self::UNKNOWN), self::withRequestId(self::problem(Response::HTTP_NOT_FOUND), 'not a request id!'), null];
        yield 'HEAD with an undocumented status' => [Request::create(self::ITEM, Request::METHOD_HEAD), self::empty(Response::HTTP_ACCEPTED), self::ITEM_PATH];
        yield 'HEAD with a body' => [Request::create(self::ITEM, Request::METHOD_HEAD), self::json('{}', Response::HTTP_OK), self::ITEM_PATH];
        yield 'body where none is documented' => [Request::create(self::ITEM, Request::METHOD_DELETE), new Response('{}', Response::HTTP_NO_CONTENT), self::ITEM_PATH];
    }

    public function testNamesTheMissingRequestId(): void
    {
        // Given
        $contract = new OpenApiContract(OpenApiDocument::fromFile(self::DOCUMENT));

        // When
        $failure = self::failureOf(static fn () => $contract->assertHonoured(Request::create(self::UNKNOWN), self::problemWithoutRequestId(), null));

        // Then
        self::assertStringContainsString('GET /unknown answered without '.RequestIdListener::HEADER, (string) $failure?->getMessage());
    }

    public function testNamesTheUndocumentedStatusAndShowsTheBody(): void
    {
        // Given
        $contract = new OpenApiContract(OpenApiDocument::fromFile(self::DOCUMENT));

        // When
        $failure = self::failureOf(static fn () => $contract->assertHonoured(self::post(self::VALID_ITEM), self::problem(Response::HTTP_INTERNAL_SERVER_ERROR), self::ITEMS));

        // Then
        self::assertStringContainsString('POST /items answered 500, which openapi.yaml does not document for post /items.', (string) $failure?->getMessage());
        self::assertStringContainsString('"status":500', (string) $failure?->getMessage());
    }

    public function testNamesTheFieldThatDoesNotMatch(): void
    {
        // Given
        $contract = new OpenApiContract(OpenApiDocument::fromFile(self::DOCUMENT));
        $response = self::problem(Response::HTTP_NOT_FOUND, '{"type":"about:blank","title":"Not Found","status":"404","detail":"Not Found"}');

        // When
        $failure = self::failureOf(static fn () => $contract->assertHonoured(Request::create(self::UNKNOWN), $response, null));

        // Then
        self::assertStringContainsString('Field: status', (string) $failure?->getMessage());
    }

    private static function failureOf(\Closure $exchange): ?AssertionFailedError
    {
        try {
            $exchange();
        } catch (AssertionFailedError $failure) {
            return $failure;
        }

        return null;
    }

    private static function post(string $body): Request
    {
        return Request::create(self::ITEMS, Request::METHOD_POST, server: ['CONTENT_TYPE' => self::JSON], content: $body);
    }

    private static function json(string $body, int $status): Response
    {
        return new Response($body, $status, [self::CONTENT_TYPE => self::JSON, RequestIdListener::HEADER => RequestIds::FROM_NGINX]);
    }

    private static function problemWithoutRequestId(): Response
    {
        return self::withoutRequestId(self::problem(Response::HTTP_NOT_FOUND));
    }

    private static function withoutRequestId(Response $response): Response
    {
        $response->headers->remove(RequestIdListener::HEADER);

        return $response;
    }

    private static function withRequestId(Response $response, string $requestId): Response
    {
        $response->headers->set(RequestIdListener::HEADER, $requestId);

        return $response;
    }

    private static function empty(int $status): Response
    {
        return new Response(status: $status, headers: [RequestIdListener::HEADER => RequestIds::FROM_NGINX]);
    }

    private static function problem(int $status, ?string $body = null): Response
    {
        return new Response($body ?? \sprintf(self::PROBLEM, $status), $status, [self::CONTENT_TYPE => ProblemJsonErrorRenderer::PROBLEM_JSON, RequestIdListener::HEADER => RequestIds::FROM_NGINX]);
    }
}
