<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\OpenApi;

use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Shared\RequestId\RequestIdListener;
use App\Tests\Support\OpenApi\OpenApiDocument;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use League\OpenAPIValidation\Schema\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class OpenApiDocumentTest extends TestCase
{
    private const string RULE_BREAKING = __DIR__.'/../../../Fixtures/openapi/rule-breaking.yaml';
    private const string ANY_STATUS = 'default';
    private const string STATUS_SEPARATOR = ' ';
    private const array ERROR_RANGES = ['4', '5'];
    private const string TYPE = 'type';
    private const array COMPOSITIONS = ['oneOf', 'anyOf', 'allOf'];
    private const string POINTER_SEPARATOR = '/';
    private const array FIXTURE_DATES = [
        '/paths//items/get/responses/200/content/application/json/example/day',
        '/paths//items/get/responses/200/content/application/json/examples/unquotedDate/value/day',
        '/paths//items/post/requestBody/content/application/json/examples/unquotedDate/value/day',
        '/components/examples/UnquotedDate/value/day',
        '/components/schemas/Day/properties/day/example',
    ];

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
     * @param list<string> $expected
     */
    #[DataProvider('documentsWithUntypedCompositions')]
    public function testGivesEveryComposedSchemaATypeSoNullIsNotAcceptedSilently(OpenApiDocument $document, array $expected): void
    {
        // When
        $untyped = array_keys(array_filter($document->schemas(), self::isUntypedComposition(...)));

        // Then
        self::assertSame($expected, $untyped);
    }

    /**
     * @return iterable<string, array{OpenApiDocument, list<string>}>
     */
    public static function documentsWithUntypedCompositions(): iterable
    {
        yield 'openapi.yaml' => [OpenApiDocument::instance(), []];
        yield 'rule-breaking fixture' => [OpenApiDocument::fromFile(self::RULE_BREAKING), ['UntypedChoice']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('documentsWithExamplesOutsideTheirSchema')]
    public function testGivesOnlyExamplesThatMatchTheirSchemaAfterPhpParsesTheYaml(OpenApiDocument $document, array $expected): void
    {
        // When
        $invalid = [
            ...array_keys(array_filter($document->requestExamples(), self::breaksItsRequestSchema(...))),
            ...array_keys(array_filter($document->responseExamples(), self::breaksItsResponseSchema(...))),
        ];

        // Then
        self::assertSame($expected, $invalid);
    }

    /**
     * @return iterable<string, array{OpenApiDocument, list<string>}>
     */
    public static function documentsWithExamplesOutsideTheirSchema(): iterable
    {
        yield 'openapi.yaml' => [OpenApiDocument::instance(), []];
        yield 'rule-breaking fixture' => [OpenApiDocument::fromFile(self::RULE_BREAKING), [
            'post /items request application/json unquotedDate',
            'get /items 200 application/json example',
            'get /items 200 application/json unquotedDate',
            'get /items 200 application/json sharedUnquotedDate',
        ]];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('documentsWithDatesThatPhpWouldNotKeepAsText')]
    public function testKeepsEveryDateAsTextWhenPhpParsesTheYaml(string $path, array $expected): void
    {
        // Given
        $tree = Yaml::parseFile($path, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_DATETIME);

        // When
        $dates = self::datesIn($tree, '');

        // Then
        self::assertSame($expected, $dates);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function documentsWithDatesThatPhpWouldNotKeepAsText(): iterable
    {
        yield 'openapi.yaml' => [OpenApiDocument::PATH, []];
        yield 'rule-breaking fixture' => [self::RULE_BREAKING, self::FIXTURE_DATES];
    }

    /**
     * @return list<string>
     */
    private static function datesIn(mixed $node, string $at): array
    {
        if ($node instanceof \DateTimeInterface) {
            return [$at];
        }

        if (\is_array($node) || $node instanceof \stdClass) {
            return self::datesUnder((array) $node, $at);
        }

        return [];
    }

    /**
     * @param array<array-key, mixed> $children
     *
     * @return list<string>
     */
    private static function datesUnder(array $children, string $at): array
    {
        $dates = [];

        foreach ($children as $key => $child) {
            array_push($dates, ...self::datesIn($child, $at.self::POINTER_SEPARATOR.$key));
        }

        return $dates;
    }

    /**
     * @param array{mixed, Schema} $example
     */
    private static function breaksItsRequestSchema(array $example): bool
    {
        return self::breaksItsSchema($example, SchemaValidator::VALIDATE_AS_REQUEST);
    }

    /**
     * @param array{mixed, Schema} $example
     */
    private static function breaksItsResponseSchema(array $example): bool
    {
        return self::breaksItsSchema($example, SchemaValidator::VALIDATE_AS_RESPONSE);
    }

    /**
     * @param array{mixed, Schema} $example
     */
    private static function breaksItsSchema(array $example, int $direction): bool
    {
        try {
            new SchemaValidator($direction)->validate($example[0], $example[1]);
        } catch (SchemaMismatch) {
            return true;
        }

        return false;
    }

    private static function isUntypedComposition(Schema $schema): bool
    {
        $keywords = array_keys((array) $schema->getSerializableData());

        if (\in_array(self::TYPE, $keywords, true)) {
            return false;
        }

        return [] !== array_intersect(self::COMPOSITIONS, $keywords);
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
        if ([ProblemJsonErrorRenderer::PROBLEM_JSON] === array_keys($response->content)) {
            return false === \in_array($response->content[ProblemJsonErrorRenderer::PROBLEM_JSON]->schema, $document->problemSchemas(), true);
        }

        return true;
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
