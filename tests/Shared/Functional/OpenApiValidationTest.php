<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Tests\Support\Controller\StreamedProblemController;
use App\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OpenApiValidationTest extends FunctionalTestCase
{
    public function testFailsAResponseThatOpenApiDoesNotDescribe(): void
    {
        // When
        $failure = $this->failureOf(fn () => $this->client->request(Request::METHOD_POST, '/_test/payload', server: ['CONTENT_TYPE' => 'application/json'], content: '{"name":"Pillars"}'));

        // Then
        self::assertStringContainsString('POST /_test/payload is not a documented operation', $failure);
    }

    public function testChecksAStreamedResponseAndStillHandsItsBodyToTheTest(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, StreamedProblemController::PATH);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSame(StreamedProblemController::BODY, $this->client->getInternalResponse()->getContent());
    }

    private function failureOf(\Closure $request): string
    {
        try {
            $request();
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        }

        self::fail('The undocumented response passed the OpenAPI check.');
    }
}
