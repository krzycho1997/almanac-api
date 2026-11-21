<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\BriefingMetrics;
use App\Briefing\FailureKind;
use App\Briefing\RefreshDecision;
use App\Briefing\Source\SettledFetch;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Shared\Metrics\Metric;
use App\Tests\Support\Briefing\BriefingResultBuilder;
use App\Tests\Support\Briefing\SourcePayloads;
use App\Tests\Support\Metrics\RecordingMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BriefingMetricsTest extends TestCase
{
    private const string NOW = '2026-11-21 23:30:00';

    #[DataProvider('days')]
    public function testLabelsADecisionWithTheDayItIsFor(string $date, string $day): void
    {
        // Given
        $recorded = new RecordingMetrics();

        // When
        new BriefingMetrics($recorded)->decided(SourceName::Weather, RefreshDecision::Fresh, new \DateTimeImmutable($date), new \DateTimeImmutable(self::NOW));

        // Then
        self::assertSame([[Metric::SnapshotDecisions, ['source' => 'weather', 'decision' => 'fresh', 'day' => $day]]], $recorded->increments);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function days(): iterable
    {
        yield 'yesterday' => ['2026-11-20', 'past'];
        yield 'today' => ['2026-11-21', 'today'];
        yield 'tomorrow' => ['2026-11-22', 'future'];
    }

    public function testCountsAFetchByItsResultAndTimesItInSeconds(): void
    {
        // Given
        $recorded = new RecordingMetrics();

        // When
        new BriefingMetrics($recorded)->fetched(new SettledFetch(SourceResult::failed(SourceName::Apod, FailureKind::RateLimited), 1250));

        // Then
        self::assertSame([[Metric::UpstreamFetches, ['source' => 'apod', 'result' => 'rate_limited']]], $recorded->increments);
        self::assertSame([[Metric::UpstreamFetchDuration, 1.25, ['source' => 'apod']]], $recorded->observations);
    }

    public function testCountsASuccessfulFetchAsOk(): void
    {
        // Given
        $recorded = new RecordingMetrics();

        // When
        new BriefingMetrics($recorded)->fetched(new SettledFetch(SourceResult::ok(SourceName::OnThisDay, SourcePayloads::onThisDayFeed()), 80));

        // Then
        self::assertSame([[Metric::UpstreamFetches, ['source' => 'on_this_day', 'result' => 'ok']]], $recorded->increments);
    }

    public function testCountsEverySourceOfABriefingByWhatWasServed(): void
    {
        // Given
        $recorded = new RecordingMetrics();
        $briefing = BriefingResultBuilder::create()
            ->stale(SourceName::Apod, SourcePayloads::apodPicture(), '2026-11-21T09:00:00Z', FailureKind::Unavailable)
            ->failed(SourceName::OnThisDay, FailureKind::Unavailable)
            ->notApplicable(SourceName::Weather)
            ->build();

        // When
        new BriefingMetrics($recorded)->served($briefing);

        // Then
        self::assertSame([
            [Metric::BriefingSourceStatus, ['source' => 'apod', 'status' => 'stale']],
            [Metric::BriefingSourceStatus, ['source' => 'on_this_day', 'status' => 'missing']],
            [Metric::BriefingSourceStatus, ['source' => 'weather', 'status' => 'not_applicable']],
        ], $recorded->increments);
    }
}
