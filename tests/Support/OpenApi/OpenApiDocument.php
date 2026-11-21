<?php

declare(strict_types=1);

namespace App\Tests\Support\OpenApi;

use cebe\openapi\Reader;
use cebe\openapi\spec\Example;
use cebe\openapi\spec\Header;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use League\OpenAPIValidation\PSR7\ValidatorBuilder as LeagueValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;

final class OpenApiDocument
{
    public const string PATH = __DIR__.'/../../../docs/openapi.yaml';
    private const string PROBLEM_SCHEMA = 'Problem';
    private const array PROBLEM_SCHEMAS = [self::PROBLEM_SCHEMA, 'ValidationProblem'];
    private const string ANY_STATUS = 'default';
    private const string STATUS_RANGE = '%dXX';
    private const int STATUSES_PER_RANGE = 100;
    private const string INLINE_EXAMPLE = 'example';
    private const string REQUEST = 'request';

    private static ?self $instance = null;

    private function __construct(
        private readonly OpenApi $document,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public static function instance(): self
    {
        return self::$instance ??= self::fromFile(self::PATH);
    }

    public static function fromFile(string $path): self
    {
        $document = Reader::readFromYamlFile($path);

        return new self($document, new ValidatorBuilder(new LeagueValidatorBuilder()->fromSchema($document))->getValidator());
    }

    public function validator(): ValidatorInterface
    {
        return $this->validator;
    }

    public function documents(string $path, string $method): bool
    {
        return $this->operation($path, $method) instanceof Operation;
    }

    public function response(string $path, string $method, int $status): ?Response
    {
        $responses = $this->operation($path, $method)?->responses;

        return self::onlyResponse($responses?->getResponse((string) $status))
            ?? self::onlyResponse($responses?->getResponse(\sprintf(self::STATUS_RANGE, intdiv($status, self::STATUSES_PER_RANGE))))
            ?? self::onlyResponse($responses?->getResponse(self::ANY_STATUS));
    }

    /**
     * @return array<string, list<string>>
     */
    public function operations(): array
    {
        $operations = [];
        foreach ($this->document->paths->getPaths() as $path => $pathItem) {
            $operations[$path] = array_keys($pathItem->getOperations());
        }

        return $operations;
    }

    /**
     * @return array<string, Response>
     */
    public function responses(): array
    {
        $responses = [];
        foreach ($this->document->paths->getPaths() as $path => $pathItem) {
            foreach ($pathItem->getOperations() as $method => $operation) {
                foreach ($operation->responses?->getResponses() ?? [] as $status => $response) {
                    $responses["{$method} {$path} {$status}"] = $response;
                }
            }
        }

        return array_filter($responses, static fn (mixed $response): bool => $response instanceof Response);
    }

    /**
     * @return array<string, array{mixed, Schema}>
     */
    public function requestExamples(): array
    {
        $examples = [];
        foreach ($this->requestBodies() as $request => $body) {
            $examples = [...$examples, ...self::examplesIn($request, $body->content)];
        }

        return $examples;
    }

    /**
     * @return array<string, array{mixed, Schema}>
     */
    public function responseExamples(): array
    {
        $examples = [];
        foreach ($this->responses() as $response => $documented) {
            $examples = [...$examples, ...self::examplesIn($response, $documented->content)];
        }

        return $examples;
    }

    public function problemSchema(): Schema
    {
        $schema = $this->document->components?->schemas[self::PROBLEM_SCHEMA] ?? null;

        if ($schema instanceof Schema) {
            return $schema;
        }

        throw new \LogicException('openapi.yaml has no Problem schema.');
    }

    /**
     * @return array<string, Schema>
     */
    public function schemas(): array
    {
        $schemas = $this->document->components->schemas ?? [];

        return array_filter($schemas, static fn (mixed $schema): bool => $schema instanceof Schema);
    }

    /**
     * @return list<Schema>
     */
    public function problemSchemas(): array
    {
        $schemas = array_map(fn (string $name): mixed => $this->document->components?->schemas[$name] ?? null, self::PROBLEM_SCHEMAS);

        return array_values(array_filter($schemas, static fn (mixed $schema): bool => $schema instanceof Schema));
    }

    public function header(string $name): Header
    {
        $header = $this->document->components?->headers[$name] ?? null;

        if ($header instanceof Header) {
            return $header;
        }

        throw new \LogicException("openapi.yaml has no {$name} header.");
    }

    /**
     * @return array<string, RequestBody>
     */
    private function requestBodies(): array
    {
        $bodies = [];
        foreach ($this->document->paths->getPaths() as $path => $pathItem) {
            foreach ($pathItem->getOperations() as $method => $operation) {
                $bodies[\sprintf('%s %s %s', $method, $path, self::REQUEST)] = $operation->requestBody;
            }
        }

        return array_filter($bodies, static fn (mixed $body): bool => $body instanceof RequestBody);
    }

    /**
     * @param array<string, MediaType> $content
     *
     * @return array<string, array{mixed, Schema}>
     */
    private static function examplesIn(string $owner, array $content): array
    {
        $examples = [];
        foreach ($content as $mediaType => $documented) {
            foreach (self::examplesOf($documented) as $name => $value) {
                $examples["{$owner} {$mediaType} {$name}"] = [$value, $documented->schema];
            }
        }

        return array_filter($examples, static fn (array $example): bool => $example[1] instanceof Schema);
    }

    /**
     * @return array<string, mixed>
     */
    private static function examplesOf(MediaType $content): array
    {
        $examples = array_map(static fn (mixed $example): mixed => $example instanceof Example ? $example->value : null, $content->examples ?? []);

        if (null === $content->example) {
            return $examples;
        }

        return [self::INLINE_EXAMPLE => $content->example, ...$examples];
    }

    private function operation(string $path, string $method): ?Operation
    {
        return $this->document->paths->getPath($path)?->getOperations()[$method] ?? null;
    }

    private static function onlyResponse(mixed $response): ?Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        return null;
    }
}
