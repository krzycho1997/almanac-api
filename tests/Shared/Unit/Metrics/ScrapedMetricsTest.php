<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Metrics;

use App\Shared\Metrics\PrometheusMetrics;
use App\Shared\Metrics\ScrapedMetrics;
use App\Tests\Support\Metrics\CountedTransport;
use PHPUnit\Framework\TestCase;
use Prometheus\Storage\InMemory;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ScrapedMetricsTest extends TestCase
{
    public function testReportsHowManyMessagesWaitInTheFailedTransport(): void
    {
        // Given
        $scraped = new ScrapedMetrics(self::metrics(), new CountedTransport(3));

        // When
        $text = $scraped->render();

        // Then
        self::assertStringContainsString("almanac_messenger_failed_messages 3\n", $text);
    }

    public function testLeavesTheGaugeOutForATransportThatCannotCount(): void
    {
        // Given
        $scraped = new ScrapedMetrics(self::metrics(), new InMemoryTransport());

        // When
        $text = $scraped->render();

        // Then
        self::assertStringNotContainsString('almanac_messenger_failed_messages', $text);
    }

    private static function metrics(): PrometheusMetrics
    {
        return new PrometheusMetrics(new InMemory(), new MockClock(), new NullLogger());
    }
}
