<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class HealthControllerTest extends FunctionalTestCase
{
    public function testReportsThatTheApplicationIsUp(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, '/api/health');

        // Then
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $health = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($health);
        self::assertSame('ok', $health['status'] ?? null);
        self::assertIsArray($health['checks'] ?? null);
    }

    public function testAnswersHeadRequests(): void
    {
        // When
        $this->client->request(Request::METHOD_HEAD, '/api/health');

        // Then
        self::assertResponseIsSuccessful();
    }

    #[TestWith([Request::METHOD_POST])]
    #[TestWith([Request::METHOD_PUT])]
    #[TestWith([Request::METHOD_DELETE])]
    public function testRejectsMethodsThatChangeState(string $method): void
    {
        // When
        $this->client->request($method, '/api/health');

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        self::assertResponseHeaderSame('Allow', Request::METHOD_GET);
    }
}
