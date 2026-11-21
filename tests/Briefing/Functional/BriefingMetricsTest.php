<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Functional;

use App\Shared\Metrics\PrometheusMetrics;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\InteractsWithTestServices;
use Symfony\Component\HttpFoundation\Request;

final class BriefingMetricsTest extends FunctionalTestCase
{
    use InteractsWithTestServices;

    private const string PAST_DAY = '/api/briefings/2024-03-15';
    private const string PICTURE = 'apod/passthrough-2024-03-15.json';

    public function testCountsAStoredPastDayAsFinalWithoutAFetch(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->onThisDayFrom('03-15')->archiveOn('2024-03-15')->apod()->fixture(self::PICTURE));
        $this->client->request(Request::METHOD_GET, self::PAST_DAY);

        // When
        $this->client->request(Request::METHOD_GET, self::PAST_DAY);

        // Then
        $metrics = self::rendered();
        foreach (['apod', 'on_this_day', 'weather'] as $source) {
            self::assertStringContainsString("almanac_snapshot_decisions_total{source=\"{$source}\",decision=\"fetch\",day=\"past\"} 1\n", $metrics);
            self::assertStringContainsString("almanac_snapshot_decisions_total{source=\"{$source}\",decision=\"final\",day=\"past\"} 1\n", $metrics);
            self::assertStringContainsString("almanac_upstream_fetches_total{source=\"{$source}\",result=\"ok\"} 1\n", $metrics);
            self::assertStringContainsString("almanac_upstream_fetch_duration_seconds_count{source=\"{$source}\"} 1\n", $metrics);
            self::assertStringContainsString("almanac_briefing_source_status_total{source=\"{$source}\",status=\"ok\"} 2\n", $metrics);
        }
    }

    private static function upstream(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }

    private static function rendered(): string
    {
        return self::getContainer()->get(PrometheusMetrics::class)->render();
    }

    protected function tearDown(): void
    {
        self::assertSame([], self::upstream()->unserved(), 'Every planned upstream response must be requested.');
        parent::tearDown();
    }
}
