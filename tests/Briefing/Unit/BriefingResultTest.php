<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\BriefingResult;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Tests\Support\Briefing\FakePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BriefingResultTest extends TestCase
{
    /**
     * @param list<SourceResult> $results
     * @param list<SourceName>   $missing
     */
    #[DataProvider('results')]
    public function testTellsWhichSourcesHaveNoData(array $results, bool $hasAnyData, array $missing): void
    {
        // Given
        $briefing = self::briefingOf($results);

        // When / Then
        self::assertSame($hasAnyData, $briefing->hasAnyData());
        self::assertSame($missing, $briefing->missing());
    }

    /**
     * @return iterable<string, array{list<SourceResult>, bool, list<SourceName>}>
     */
    public static function results(): iterable
    {
        $ok = SourceResult::ok(SourceName::Apod, new FakePayload([]));
        $failed = SourceResult::failed(SourceName::OnThisDay, FailureKind::Unavailable);
        $notApplicable = SourceResult::notApplicable(SourceName::Weather);

        yield 'every source has data' => [[$ok], true, []];
        yield 'some sources have data' => [[$ok, $failed, $notApplicable], true, [SourceName::OnThisDay, SourceName::Weather]];
        yield 'no source has data' => [[$failed, $notApplicable], false, [SourceName::OnThisDay, SourceName::Weather]];
    }

    public function testFindsTheResultOfASource(): void
    {
        // Given
        $failed = SourceResult::failed(SourceName::OnThisDay, FailureKind::Unavailable);
        $briefing = self::briefingOf([SourceResult::notApplicable(SourceName::Weather), $failed]);

        // When
        $result = $briefing->resultFor(SourceName::OnThisDay);

        // Then
        self::assertSame($failed, $result);
    }

    public function testRefusesASourceWithoutAResult(): void
    {
        // Given
        $briefing = self::briefingOf([SourceResult::notApplicable(SourceName::Weather)]);

        // Then / When
        $this->expectException(\LogicException::class);
        $briefing->resultFor(SourceName::Apod);
    }

    /**
     * @param list<SourceResult> $results
     */
    private static function briefingOf(array $results): BriefingResult
    {
        $now = new \DateTimeImmutable('2026-11-18 12:00');

        return new BriefingResult($now, Location::fromCoordinates(52.23, 21.01), $results, $now);
    }
}
