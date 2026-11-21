<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Shared\Metrics\PrometheusMetrics;
use App\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Request;

final class HttpMetricsTest extends FunctionalTestCase
{
    public function testCountsARequestByItsRouteMethodAndCode(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, '/api/health');

        // Then
        self::assertStringContainsString('almanac_http_requests_total{route="shared_health",method="GET",code="200"} 1'."\n", self::rendered());
    }

    public function testCountsARequestThatMatchesNoRouteUnderOneLabel(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, '/api/nothing-here/2024-03-15');

        // Then
        self::assertStringContainsString('almanac_http_requests_total{route="unmatched",method="GET",code="404"} 1'."\n", self::rendered());
    }

    public function testCountsAnUnusualMethodAsOther(): void
    {
        // When
        $this->client->request('PROPFIND', '/api/health');

        // Then
        self::assertStringContainsString('almanac_http_requests_total{route="unmatched",method="other",code="405"} 1'."\n", self::rendered());
    }

    public function testTimesTheRequestByItsRoute(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, '/api/health');

        // Then
        self::assertStringContainsString('almanac_http_request_duration_seconds_count{route="shared_health"} 1'."\n", self::rendered());
    }

    private static function rendered(): string
    {
        return self::getContainer()->get(PrometheusMetrics::class)->render();
    }
}
