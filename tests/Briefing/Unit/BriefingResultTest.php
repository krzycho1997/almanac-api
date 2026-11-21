<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\BriefingProblem;
use App\Briefing\BriefingResult;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\ProblemTiming;
use App\Briefing\SourceEntry;
use App\Briefing\SourceFailure;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Tests\Support\Briefing\FakePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BriefingResultTest extends TestCase
{
    private const string NOW = '2026-11-18 12:00';

    /**
     * @param list<SourceEntry> $results
     * @param list<SourceName>  $missing
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
     * @return iterable<string, array{list<SourceEntry>, bool, list<SourceName>}>
     */
    public static function results(): iterable
    {
        $ok = SourceEntry::fresh(SourceName::Apod, new FakePayload([]), new \DateTimeImmutable(self::NOW));
        $failed = SourceEntry::missing(SourceName::OnThisDay, new SourceFailure(FailureKind::Unavailable), new \DateTimeImmutable(self::NOW));
        $notApplicable = SourceEntry::notApplicable(SourceName::Weather);
        $stale = SourceEntry::stale(SourceName::Weather, new FakePayload([]), new \DateTimeImmutable(self::NOW), new SourceFailure(FailureKind::Unavailable), new \DateTimeImmutable(self::NOW));

        yield 'every source has data' => [[$ok], true, []];
        yield 'some sources have data' => [[$ok, $failed, $notApplicable], true, [SourceName::OnThisDay, SourceName::Weather]];
        yield 'no source has data' => [[$failed, $notApplicable], false, [SourceName::OnThisDay, SourceName::Weather]];
        yield 'stale data counts as data' => [[$failed, $stale], true, [SourceName::OnThisDay]];
    }

    public function testListsTheFailuresOfThisRunOnly(): void
    {
        // Given
        $failedNow = SourceResult::failed(SourceName::Weather, FailureKind::Unavailable, 503);
        $briefing = new BriefingResult(new \DateTimeImmutable(self::NOW), Location::fromCoordinates(52.23, 21.01), [], [SourceResult::ok(SourceName::Apod, new FakePayload([])), $failedNow]);

        // When
        $failures = $briefing->failures();

        // Then
        self::assertSame([$failedNow], $failures);
    }

    /**
     * @param list<SourceEntry>  $entries
     * @param list<SourceResult> $results
     */
    #[DataProvider('problems')]
    public function testTellsWhetherARunLeftAProblem(array $entries, array $results, bool $expected): void
    {
        // When
        $hasProblems = new BriefingResult(new \DateTimeImmutable(self::NOW), Location::fromCoordinates(52.23, 21.01), $entries, $results)->hasProblems();

        // Then
        self::assertSame($expected, $hasProblems);
    }

    /**
     * @return iterable<string, array{list<SourceEntry>, list<SourceResult>, bool}>
     */
    public static function problems(): iterable
    {
        $now = new \DateTimeImmutable(self::NOW);
        $failure = new SourceFailure(FailureKind::Unavailable);
        $fresh = SourceEntry::fresh(SourceName::Apod, new FakePayload([]), $now);
        $stale = SourceEntry::stale(SourceName::Apod, new FakePayload([]), $now, $failure, $now);
        $missing = SourceEntry::missing(SourceName::Apod, $failure, $now);

        yield 'everything fresh' => [[$fresh, SourceEntry::notApplicable(SourceName::Weather)], [SourceResult::ok(SourceName::Apod, new FakePayload([]))], false];
        yield 'old data served stale, nothing fetched' => [[$stale], [], false];
        yield 'a fetch failed now' => [[$stale], [SourceResult::failed(SourceName::Apod, FailureKind::Unavailable)], true];
        yield 'no data while the source pauses' => [[$missing], [], true];
    }

    public function testListsTheFailuresOfThisRunThenTheSourcesStillInTheirPause(): void
    {
        // Given
        $now = new \DateTimeImmutable(self::NOW);
        $failure = new SourceFailure(FailureKind::Unavailable);
        $inPause = SourceEntry::missing(SourceName::Apod, $failure, $now);
        $failedNow = SourceEntry::missing(SourceName::OnThisDay, $failure, $now);
        $stale = SourceEntry::stale(SourceName::Weather, new FakePayload([]), $now, $failure, $now);
        $briefing = new BriefingResult($now, Location::fromCoordinates(52.23, 21.01), [$inPause, $failedNow, $stale], [SourceResult::failed(SourceName::OnThisDay, FailureKind::Unavailable, 503)]);

        // When
        $problems = $briefing->problems();

        // Then
        self::assertEquals([
            new BriefingProblem(SourceName::OnThisDay, new SourceFailure(FailureKind::Unavailable, 503), ProblemTiming::FailedNow),
            new BriefingProblem(SourceName::Apod, $failure, ProblemTiming::InPause),
        ], $problems);
    }

    public function testTellsUntilWhenTheLatestRefusalLasts(): void
    {
        // Given
        $now = new \DateTimeImmutable(self::NOW);
        $results = [
            SourceResult::circuitOpen(SourceName::Apod, $now->modify('+1 minute')),
            SourceResult::failed(SourceName::OnThisDay, FailureKind::RateLimited, 429, $now->modify('+2 hours')),
            SourceResult::circuitOpen(SourceName::Weather, $now->modify('+15 minutes')),
        ];

        // When
        $until = new BriefingResult($now, Location::fromCoordinates(52.23, 21.01), [], $results)->refusedUntil();

        // Then
        self::assertEquals($now->modify('+15 minutes'), $until);
        self::assertNull(new BriefingResult($now, Location::fromCoordinates(52.23, 21.01), [], [$results[1]])->refusedUntil());
    }

    public function testCountsASourceRefusedByAnOpenCircuitAsASkippedProblem(): void
    {
        // Given
        $now = new \DateTimeImmutable(self::NOW);
        $refused = SourceEntry::missing(SourceName::Apod, new SourceFailure(FailureKind::CircuitOpen), $now);
        $briefing = new BriefingResult($now, Location::fromCoordinates(52.23, 21.01), [$refused], [SourceResult::circuitOpen(SourceName::Apod, new \DateTimeImmutable(self::NOW))]);

        // When
        $problems = $briefing->problems();

        // Then
        self::assertEquals([new BriefingProblem(SourceName::Apod, new SourceFailure(FailureKind::CircuitOpen, retryAt: $now), ProblemTiming::Skipped)], $problems);
        self::assertEquals([SourceResult::circuitOpen(SourceName::Apod, new \DateTimeImmutable(self::NOW))], $briefing->failures());
    }

    /**
     * @param list<SourceResult> $results
     */
    #[DataProvider('fetches')]
    public function testTellsWhetherItCalledAnUpstream(array $results, bool $expected): void
    {
        // Given
        $briefing = new BriefingResult(new \DateTimeImmutable(self::NOW), Location::fromCoordinates(52.23, 21.01), [], $results);

        // When / Then
        self::assertSame($expected, $briefing->hasFetched());
    }

    /**
     * @return iterable<string, array{list<SourceResult>, bool}>
     */
    public static function fetches(): iterable
    {
        yield 'nothing fetched' => [[], false];
        yield 'only refused by open circuits' => [[SourceResult::circuitOpen(SourceName::Apod, new \DateTimeImmutable(self::NOW)), SourceResult::circuitOpen(SourceName::Weather, new \DateTimeImmutable(self::NOW))], false];
        yield 'one failed fetch' => [[SourceResult::failed(SourceName::Apod, FailureKind::Unavailable)], true];
        yield 'a fetch next to a refusal' => [[SourceResult::circuitOpen(SourceName::Apod, new \DateTimeImmutable(self::NOW)), SourceResult::failed(SourceName::Weather, FailureKind::Rejected)], true];
    }

    public function testFindsTheResultOfASource(): void
    {
        // Given
        $failed = SourceEntry::missing(SourceName::OnThisDay, new SourceFailure(FailureKind::Unavailable), new \DateTimeImmutable(self::NOW));
        $briefing = self::briefingOf([SourceEntry::notApplicable(SourceName::Weather), $failed]);

        // When
        $result = $briefing->entryFor(SourceName::OnThisDay);

        // Then
        self::assertSame($failed, $result);
    }

    public function testRefusesASourceWithoutAResult(): void
    {
        // Given
        $briefing = self::briefingOf([SourceEntry::notApplicable(SourceName::Weather)]);

        // Then / When
        $this->expectException(\LogicException::class);
        $briefing->entryFor(SourceName::Apod);
    }

    /**
     * @param list<SourceEntry> $entries
     */
    private static function briefingOf(array $entries): BriefingResult
    {
        $now = new \DateTimeImmutable(self::NOW);

        return new BriefingResult($now, Location::fromCoordinates(52.23, 21.01), $entries, []);
    }
}
