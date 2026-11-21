<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MetricsEndpointTest extends FunctionalTestCase
{
    public function testHidesTheMetricsFromARequestThatDidNotComeThroughTheScrapePort(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, '/metrics');

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }
}
