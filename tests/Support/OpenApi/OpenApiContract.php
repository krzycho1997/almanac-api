<?php

declare(strict_types=1);

namespace App\Tests\Support\OpenApi;

use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Shared\RequestId\RequestIdListener;
use cebe\openapi\spec\Header;
use cebe\openapi\spec\Response as DocumentedResponse;
use cebe\openapi\spec\Schema;
use League\OpenAPIValidation\PSR7\Validators\SerializedParameter;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use League\OpenAPIValidation\Schema\SchemaValidator;
use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\ProblemNormalizer;

final readonly class OpenApiContract
{
    private const string CONTENT_TYPE = 'Content-Type';
    private const string REQUEST_ID_HEADER = 'RequestId';
    private const string GET = 'get';
    private const string HEAD = 'head';
    private const string NO_BODY = '';
    private const string FIELD_SEPARATOR = '.';
    private const int EXCERPT_LENGTH = 300;

    public function __construct(private OpenApiDocument $document)
    {
    }

    public function assertHonoured(Request $request, Response $response, ?string $path): void
    {
        if (null === $path) {
            $this->assertProblem($request, $response);

            return;
        }

        $this->assertRouted($request, $response, $path);
    }

    private function assertRouted(Request $request, Response $response, string $path): void
    {
        $method = strtolower($request->getMethod());

        if ($this->document->documents($path, $method)) {
            $this->assertOperation($request, $response, $path, $method);

            return;
        }

        if ($this->isHeadOfDocumentedGet($path, $method)) {
            $this->assertHead($request, $response, $path);

            return;
        }

        $this->assertProblem($request, $response);
    }

    private function isHeadOfDocumentedGet(string $path, string $method): bool
    {
        if (self::HEAD === $method) {
            return $this->document->documents($path, self::GET);
        }

        return false;
    }

    private function assertOperation(Request $request, Response $response, string $path, string $method): void
    {
        $documented = $this->documentedResponse($request, $response, $path, $method);

        if ($response->isSuccessful()) {
            $this->validated($request, $response, fn (): bool => $this->document->validator()->validate($request, $path, $method));
        }

        $this->validated($request, $response, fn (): bool => $this->document->validator()->validate($response, $path, $method));
        $this->assertNoUndocumentedBody($request, $response, $documented);
        $this->assertProblemStatus($request, $response);
    }

    private function assertHead(Request $request, Response $response, string $path): void
    {
        $documented = $this->documentedResponse($request, $response, $path, self::GET);

        foreach ($documented->headers as $name => $header) {
            Assert::assertInstanceOf(Header::class, $header);
            $this->assertHeader($request, $response, $name, $header);
        }

        Assert::assertSame(self::NO_BODY, (string) $response->getContent(), self::describe($request).' answered HEAD with a body.');
    }

    private function assertProblem(Request $request, Response $response): void
    {
        $where = self::describe($request);
        Assert::assertGreaterThanOrEqual(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), "{$where} is not a documented operation, so it must answer with an error.".self::excerpt($response));
        Assert::assertSame(ProblemJsonErrorRenderer::PROBLEM_JSON, $response->headers->get(self::CONTENT_TYPE), "{$where} is not a documented operation, so it must answer with Problem Details.".self::excerpt($response));
        $this->matches($request, $response, json_decode((string) $response->getContent(), true), $this->document->problemSchema());
        $this->assertProblemStatus($request, $response);
        $this->assertHeader($request, $response, RequestIdListener::HEADER, $this->document->header(self::REQUEST_ID_HEADER));
    }

    private function documentedResponse(Request $request, Response $response, string $path, string $method): DocumentedResponse
    {
        $status = $response->getStatusCode();
        $documented = $this->document->response($path, $method, $status);

        if ($documented instanceof DocumentedResponse) {
            return $documented;
        }

        Assert::fail(self::describe($request)." answered {$status}, which openapi.yaml does not document for {$method} {$path}.".self::excerpt($response));
    }

    private function assertNoUndocumentedBody(Request $request, Response $response, DocumentedResponse $documented): void
    {
        if ([] === $documented->content) {
            Assert::assertSame(self::NO_BODY, (string) $response->getContent(), self::describe($request).' answered with a body that openapi.yaml does not document.');
        }
    }

    private function assertProblemStatus(Request $request, Response $response): void
    {
        if (ProblemJsonErrorRenderer::PROBLEM_JSON === $response->headers->get(self::CONTENT_TYPE)) {
            Assert::assertSame($response->getStatusCode(), self::problemStatus($response), self::describe($request).' answered with a Problem whose status is not the HTTP status.');
        }
    }

    private function assertHeader(Request $request, Response $response, string $name, Header $header): void
    {
        $value = $response->headers->get($name);

        if (null === $value) {
            Assert::assertFalse($header->required, self::describe($request)." answered without {$name}.");

            return;
        }

        Assert::assertInstanceOf(Schema::class, $header->schema);
        $this->matches($request, $response, SerializedParameter::fromSpec($header)->deserialize($value), $header->schema);
    }

    private function matches(Request $request, Response $response, mixed $data, Schema $schema): void
    {
        try {
            new SchemaValidator()->validate($data, $schema);
        } catch (SchemaMismatch $mismatch) {
            Assert::fail(self::describe($request).' does not match openapi.yaml: '.$mismatch->getMessage().self::field($mismatch).self::excerpt($response));
        }
    }

    /**
     * @param \Closure(): bool $validation
     */
    private function validated(Request $request, Response $response, \Closure $validation): void
    {
        try {
            $validation();
        } catch (ValidationException $exception) {
            Assert::fail(self::describe($request).' does not match openapi.yaml: '.$exception->getMessage().self::excerpt($response));
        }
    }

    private static function problemStatus(Response $response): mixed
    {
        $problem = json_decode((string) $response->getContent(), true);

        if (\is_array($problem)) {
            return $problem[ProblemNormalizer::STATUS] ?? null;
        }

        return null;
    }

    private static function field(SchemaMismatch $mismatch): string
    {
        $chain = $mismatch->dataBreadCrumb()?->buildChain() ?? [];

        if ([] === $chain) {
            return self::NO_BODY;
        }

        return ' Field: '.implode(self::FIELD_SEPARATOR, array_map(self::crumb(...), $chain));
    }

    private static function crumb(mixed $crumb): string
    {
        if (\is_scalar($crumb)) {
            return (string) $crumb;
        }

        return self::NO_BODY;
    }

    private static function excerpt(Response $response): string
    {
        return "\nResponse ".$response->getStatusCode().': '.mb_substr((string) $response->getContent(), 0, self::EXCERPT_LENGTH);
    }

    private static function describe(Request $request): string
    {
        return "{$request->getMethod()} {$request->getPathInfo()}";
    }
}
