<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\TestWith;

final class HealthControllerTest extends FunctionalTestCase
{
    public function testReportsThatTheApplicationIsUp(): void
    {
        // When
        $this->client->request('GET', '/api/health');

        // Then
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $this->client->getResponse()->getContent());
    }

    public function testAnswersHeadRequests(): void
    {
        // When
        $this->client->request('HEAD', '/api/health');

        // Then
        self::assertResponseIsSuccessful();
    }

    #[TestWith(['POST'])]
    #[TestWith(['PUT'])]
    #[TestWith(['DELETE'])]
    public function testRejectsMethodsThatChangeState(string $method): void
    {
        // When
        $this->client->request($method, '/api/health');

        // Then
        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Allow', 'GET');
    }
}
