<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\EndToEnd\HttpProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class NginxMetricsTest extends TestCase
{
    private const string METRICS = '/metrics';
    private const int SCRAPE_PORT = 9100;
    private const string REQUEST_ID = 'e2e-label-probe-7f3a';
    private const string LABEL = '/(\w+)="([^"]*)"/';
    private const string BUCKET_BOUND = 'le';
    private const array LABEL_NAMES = ['code', 'day', 'decision', 'method', 'result', 'route', 'source', 'status', 'to'];
    private const string BOUNDED_VALUE = '/^(?:[a-z_]+|[A-Z]+|\d{3}|[1-5]xx)$/D';

    public function testHidesTheMetricsOnThePublicPort(): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get(self::METRICS);

        // Then
        self::assertSame(Response::HTTP_NOT_FOUND, $response->status);
        self::assertSame(['application/problem+json'], $response->headerValues('Content-Type'));
    }

    public function testServesTheMetricsOnTheScrapePortInsideTheNetwork(): void
    {
        // When
        $response = HttpProbe::fromEnvironmentOnPort(self::SCRAPE_PORT)->get(self::METRICS);

        // Then
        self::assertSame(Response::HTTP_OK, $response->status);
        self::assertSame(['text/plain; version=0.0.4; charset=UTF-8'], $response->headerValues('Content-Type'));
        self::assertStringContainsString('# TYPE almanac_http_requests_total counter', $response->body);
    }

    public function testKeepsDatesPlacesIdsAndAddressesOutOfTheLabels(): void
    {
        // Given
        $public = HttpProbe::fromEnvironment();
        $headers = ['X-Request-Id' => self::REQUEST_ID];
        $public->get('/api/briefings/2024-03-15?lat=48.86&lon=999', $headers);
        $public->get('/api/briefings/1990-01-01', $headers);
        $public->get('/api/bookmarks/0192f4f6-1c2b-7d3e-8f40-5a6b7c8d9e0f', $headers);
        $public->get('/api/nothing-here/2024-03-15/52.23,21.01', $headers);

        // When
        $labels = self::labelsIn(HttpProbe::fromEnvironmentOnPort(self::SCRAPE_PORT)->get(self::METRICS)->body);

        // Then
        self::assertNotEmpty($labels);
        foreach ($labels as [$name, $value]) {
            self::assertContains($name, self::LABEL_NAMES);
            self::assertMatchesRegularExpression(self::BOUNDED_VALUE, $value, "{$name}=\"{$value}\"");
        }
    }

    /**
     * @return list<array{string, string}>
     */
    private static function labelsIn(string $metrics): array
    {
        preg_match_all(self::LABEL, $metrics, $matches, \PREG_SET_ORDER);

        return array_values(array_map(
            static fn (array $match): array => [$match[1], $match[2]],
            array_filter($matches, static fn (array $match): bool => self::BUCKET_BOUND !== $match[1]),
        ));
    }
}
