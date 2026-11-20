<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\OpenApi;

use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Shared\RequestId\RequestIdListener;
use App\Tests\Support\OpenApi\OpenApiDocument;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenApiDocumentTest extends TestCase
{
    private const string RULE_BREAKING = __DIR__.'/../../../Fixtures/openapi/rule-breaking.yaml';
    private const string ANY_STATUS = 'default';
    private const string STATUS_SEPARATOR = ' ';
    private const array ERROR_RANGES = ['4', '5'];

    /**
     * @param list<string> $expected
     */
    #[DataProvider('documentsWithErrorsOutsideProblemDetails')]
    public function testDescribesEveryErrorAsProblemDetails(OpenApiDocument $document, array $expected): void
    {
        // When
        $errors = self::errorsOutsideProblemDetails($document);

        // Then
        self::assertSame($expected, $errors);
    }

    /**
     * @return iterable<string, array{OpenApiDocument, list<string>}>
     */
    public static function documentsWithErrorsOutsideProblemDetails(): iterable
    {
        yield 'openapi.yaml' => [OpenApiDocument::instance(), []];
        yield 'rule-breaking fixture' => [OpenApiDocument::fromFile(self::RULE_BREAKING), ['get /items 404', 'get /items 4XX', 'get /items default']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('documentsWithResponsesWithoutRequestId')]
    public function testDeclaresTheRequestIdOnEveryResponse(OpenApiDocument $document, array $expected): void
    {
        // When
        $withoutRequestId = array_keys(array_filter($document->responses(), self::lacksRequestId(...)));

        // Then
        self::assertSame($expected, $withoutRequestId);
    }

    /**
     * @return iterable<string, array{OpenApiDocument, list<string>}>
     */
    public static function documentsWithResponsesWithoutRequestId(): iterable
    {
        yield 'openapi.yaml' => [OpenApiDocument::instance(), []];
        yield 'rule-breaking fixture' => [OpenApiDocument::fromFile(self::RULE_BREAKING), ['get /items 503']];
    }

    /**
     * @return list<string>
     */
    private static function errorsOutsideProblemDetails(OpenApiDocument $document): array
    {
        $errors = array_filter($document->responses(), self::isError(...), \ARRAY_FILTER_USE_KEY);

        return array_keys(array_filter($errors, static fn (Response $response): bool => self::isOutsideProblemDetails($document, $response)));
    }

    private static function isOutsideProblemDetails(OpenApiDocument $document, Response $response): bool
    {
        $schemas = array_map(static fn (MediaType $mediaType): mixed => $mediaType->schema, $response->content);

        return [ProblemJsonErrorRenderer::PROBLEM_JSON => $document->problemSchema()] !== $schemas;
    }

    private static function lacksRequestId(Response $response): bool
    {
        return null === ($response->headers[RequestIdListener::HEADER] ?? null);
    }

    private static function isError(string $response): bool
    {
        $status = substr($response, (int) strrpos($response, self::STATUS_SEPARATOR) + 1);

        if (self::ANY_STATUS === $status) {
            return true;
        }

        return \in_array($status[0], self::ERROR_RANGES, true);
    }
}
