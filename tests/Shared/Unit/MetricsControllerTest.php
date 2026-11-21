<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Controller\MetricsController;
use App\Shared\Metrics\Metric;
use App\Shared\Metrics\PrometheusMetrics;
use App\Shared\Metrics\ScrapedMetrics;
use App\Tests\Support\Metrics\CountedTransport;
use PHPUnit\Framework\TestCase;
use Prometheus\Storage\InMemory;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MetricsControllerTest extends TestCase
{
    public function testRendersTheMetricsForTheScrapePort(): void
    {
        // Given
        $metrics = self::metrics();
        $metrics->increment(Metric::CircuitTransitions, ['source' => 'apod', 'to' => 'open']);
        $request = Request::create(MetricsController::PATH, server: [MetricsController::SCRAPE_PARAMETER => '1']);

        // When
        $response = new MetricsController(new ScrapedMetrics($metrics, new CountedTransport(2)))($request);

        // Then
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; version=0.0.4; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('almanac_circuit_transitions_total{source="apod",to="open"} 1'."\n", (string) $response->getContent());
        self::assertStringContainsString("almanac_messenger_failed_messages 2\n", (string) $response->getContent());
    }

    public function testHidesTheMetricsWithoutTheScrapeParameter(): void
    {
        // Given
        $controller = new MetricsController(new ScrapedMetrics(self::metrics(), new CountedTransport(0)));

        // Then / When
        $this->expectException(NotFoundHttpException::class);
        $controller(Request::create(MetricsController::PATH, server: ['HTTP_ALMANAC_METRICS_SCRAPE' => '1']));
    }

    private static function metrics(): PrometheusMetrics
    {
        return new PrometheusMetrics(new InMemory(), new MockClock(), new NullLogger());
    }
}
