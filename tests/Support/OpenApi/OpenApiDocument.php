<?php

declare(strict_types=1);

namespace App\Tests\Support\OpenApi;

use cebe\openapi\Reader;
use cebe\openapi\spec\Header;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use League\OpenAPIValidation\PSR7\ValidatorBuilder as LeagueValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;

final class OpenApiDocument
{
    public const string PATH = __DIR__.'/../../../docs/openapi.yaml';
    private const string PROBLEM_SCHEMA = 'Problem';
    private const string ANY_STATUS = 'default';
    private const string STATUS_RANGE = '%dXX';
    private const int STATUSES_PER_RANGE = 100;

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

    public function problemSchema(): Schema
    {
        $schema = $this->document->components?->schemas[self::PROBLEM_SCHEMA] ?? null;

        if ($schema instanceof Schema) {
            return $schema;
        }

        throw new \LogicException('openapi.yaml has no Problem schema.');
    }

    public function header(string $name): Header
    {
        $header = $this->document->components?->headers[$name] ?? null;

        if ($header instanceof Header) {
            return $header;
        }

        throw new \LogicException("openapi.yaml has no {$name} header.");
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
