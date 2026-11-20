<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\RefreshDecision;
use App\Briefing\RefreshPolicy;
use App\Briefing\SnapshotState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshPolicyTest extends TestCase
{
    private const string NOW = '2026-11-17 12:00:00';
    private const string WARSAW = 'Europe/Warsaw';
    private const string TODAY = '2026-11-17';
    private const string YESTERDAY = '2026-11-16';
    private const string TOMORROW = '2026-11-18';
    private const string HOURLY = 'PT1H';

    #[DataProvider('decisions')]
    public function testDecidesWhetherToFetchASource(?SnapshotState $state, ?string $refreshAfter, string $date, RefreshDecision $expected): void
    {
        // When
        $decision = new RefreshPolicy()->decide($state, self::interval($refreshAfter), self::at($date), self::at(self::NOW));

        // Then
        self::assertSame($expected, $decision);
    }

    /**
     * @return iterable<string, array{?SnapshotState, ?string, string, RefreshDecision}>
     */
    public static function decisions(): iterable
    {
        yield '1: no snapshot yet' => [null, null, self::YESTERDAY, RefreshDecision::Fetch];
        yield '1: no snapshot for today' => [null, self::HOURLY, self::TODAY, RefreshDecision::Fetch];
        yield '2: stored past day of a final source' => [self::fetched('-3 days'), null, self::YESTERDAY, RefreshDecision::Final];
        yield '2: stored past day of an hourly source' => [self::fetched('-3 days'), self::HOURLY, self::YESTERDAY, RefreshDecision::Final];
        yield '2: stored past day whose refresh failed a second ago' => [self::failed('-1 second', true), self::HOURLY, self::YESTERDAY, RefreshDecision::Final];
        yield '2: stored past day whose refresh failed long ago' => [self::failed('-1 day', true), self::HOURLY, self::YESTERDAY, RefreshDecision::Final];
        yield '3: past day without data failed just under the pause' => [self::failed('-9 minutes -59 seconds', false), null, self::YESTERDAY, RefreshDecision::Wait];
        yield '4: past day without data failed exactly the pause ago' => [self::failed('-10 minutes', false), null, self::YESTERDAY, RefreshDecision::Fetch];
        yield '3: past day of an hourly source without data failed a minute ago' => [self::failed('-1 minute', false), self::HOURLY, self::YESTERDAY, RefreshDecision::Wait];
        yield '4: past day of an hourly source without data failed long ago' => [self::failed('-1 day', false), self::HOURLY, self::YESTERDAY, RefreshDecision::Fetch];
        yield '3: today with data failed just under the pause' => [self::failed('-9 minutes -59 seconds', true), self::HOURLY, self::TODAY, RefreshDecision::Wait];
        yield '4: today with data failed exactly the pause ago' => [self::failed('-10 minutes', true), self::HOURLY, self::TODAY, RefreshDecision::Fetch];
        yield '3: a final source without data failing today waits too' => [self::failed('-1 minute', false), null, self::TODAY, RefreshDecision::Wait];
        yield '4: a final source without data failed exactly the pause ago' => [self::failed('-10 minutes', false), null, self::TOMORROW, RefreshDecision::Fetch];
        yield '5: today of a final source whose later attempt failed' => [self::failed('-1 second', true), null, self::TODAY, RefreshDecision::Final];
        yield '5: future day of a final source whose later attempt failed long ago' => [self::failed('-1 day', true), null, self::TOMORROW, RefreshDecision::Final];
        yield '5: today of a final source' => [self::fetched('-2 days'), null, self::TODAY, RefreshDecision::Final];
        yield '5: future day of a final source' => [self::fetched('-2 days'), null, self::TOMORROW, RefreshDecision::Final];
        yield '6: today fetched just under an hour ago' => [self::fetched('-59 minutes -59 seconds'), self::HOURLY, self::TODAY, RefreshDecision::Fresh];
        yield '6: today fetched exactly an hour ago' => [self::fetched('-1 hour'), self::HOURLY, self::TODAY, RefreshDecision::Fetch];
        yield '6: future day fetched just under an hour ago' => [self::fetched('-59 minutes -59 seconds'), self::HOURLY, self::TOMORROW, RefreshDecision::Fresh];
        yield '6: future day fetched exactly an hour ago' => [self::fetched('-1 hour'), self::HOURLY, self::TOMORROW, RefreshDecision::Fetch];
    }

    #[DataProvider('daysAroundMidnight')]
    public function testTellsPastDaysByTheCalendarDayOfNow(string $date, string $now, RefreshDecision $expected): void
    {
        // Given
        $justFetched = SnapshotState::fetchedAt(new \DateTimeImmutable($now)->modify('-1 minute'));

        // When
        $decision = new RefreshPolicy()->decide($justFetched, new \DateInterval(self::HOURLY), new \DateTimeImmutable($date), new \DateTimeImmutable($now));

        // Then
        self::assertSame($expected, $decision);
    }

    /**
     * @return iterable<string, array{string, string, RefreshDecision}>
     */
    public static function daysAroundMidnight(): iterable
    {
        yield 'today in its last second' => ['2026-11-17 00:00:00 Europe/Warsaw', '2026-11-17 23:59:59 Europe/Warsaw', RefreshDecision::Fresh];
        yield 'yesterday at the first second of today' => ['2026-11-16 00:00:00 Europe/Warsaw', '2026-11-17 00:00:00 Europe/Warsaw', RefreshDecision::Final];
        yield 'a stored UTC date that is still today in New York' => ['2026-11-16 00:00:00 UTC', '2026-11-16 20:00:00 America/New_York', RefreshDecision::Fresh];
        yield 'a date that is today in Warsaw but yesterday in UTC' => ['2026-11-17 00:00:00 Europe/Warsaw', '2026-11-17 00:30:00 Europe/Warsaw', RefreshDecision::Fresh];
        yield 'yesterday in Warsaw while UTC is still on that day' => ['2026-11-16 00:00:00 Europe/Warsaw', '2026-11-17 00:30:00 Europe/Warsaw', RefreshDecision::Final];
    }

    #[DataProvider('configuredPause')]
    public function testWaitsAsLongAsTheConfiguredPause(string $failedAt, RefreshDecision $expected): void
    {
        // Given
        $policy = new RefreshPolicy(new \DateInterval('PT1M'));
        $failed = SnapshotState::failedAt(self::at($failedAt), false);

        // When
        $decision = $policy->decide($failed, null, self::at(self::TODAY), self::at(self::NOW));

        // Then
        self::assertSame($expected, $decision);
    }

    /**
     * @return iterable<string, array{string, RefreshDecision}>
     */
    public static function configuredPause(): iterable
    {
        yield 'just under the pause' => ['2026-11-17 11:59:01', RefreshDecision::Wait];
        yield 'exactly the pause' => ['2026-11-17 11:59:00', RefreshDecision::Fetch];
    }

    #[DataProvider('fetchingDecisions')]
    public function testTellsWhichDecisionsCallTheUpstream(RefreshDecision $decision, bool $expected): void
    {
        // When / Then
        self::assertSame($expected, $decision->fetches());
    }

    /**
     * @return iterable<string, array{RefreshDecision, bool}>
     */
    public static function fetchingDecisions(): iterable
    {
        yield 'fetch' => [RefreshDecision::Fetch, true];
        yield 'wait' => [RefreshDecision::Wait, false];
        yield 'fresh' => [RefreshDecision::Fresh, false];
        yield 'final' => [RefreshDecision::Final, false];
    }

    private static function fetched(string $ago): SnapshotState
    {
        return SnapshotState::fetchedAt(self::at(self::NOW)->modify($ago));
    }

    private static function failed(string $ago, bool $hasPayload): SnapshotState
    {
        return SnapshotState::failedAt(self::at(self::NOW)->modify($ago), $hasPayload);
    }

    private static function interval(?string $refreshAfter): ?\DateInterval
    {
        if (null === $refreshAfter) {
            return null;
        }

        return new \DateInterval($refreshAfter);
    }

    private static function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone(self::WARSAW));
    }
}
