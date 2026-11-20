<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\FailureKind;
use App\Briefing\FailureOutcome;
use App\Briefing\SourceEntry;
use App\Briefing\SourceFailure;
use App\Briefing\SourceName;
use App\Tests\Support\Briefing\SourcePayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceEntryTest extends TestCase
{
    private const string FETCHED_AT = '2026-11-18 09:00:00';
    private const string ATTEMPTED_AT = '2026-11-18 12:00:00';

    /**
     * @param array{bool, bool, ?string, ?string} $expected
     */
    #[DataProvider('entries')]
    public function testTellsWhatItHoldsAndWhen(SourceEntry $entry, array $expected, FailureOutcome $outcome): void
    {
        // When / Then
        self::assertSame($expected, [$entry->hasData(), $entry->hasFailed(), $entry->fetchedAt?->format('H:i'), $entry->lastAttemptAt?->format('H:i')]);
        self::assertSame($outcome, FailureOutcome::of($entry));
    }

    /**
     * @return iterable<string, array{SourceEntry, array{bool, bool, ?string, ?string}, FailureOutcome}>
     */
    public static function entries(): iterable
    {
        $fetchedAt = new \DateTimeImmutable(self::FETCHED_AT);
        $attemptedAt = new \DateTimeImmutable(self::ATTEMPTED_AT);
        $failure = new SourceFailure(FailureKind::Unavailable);

        yield 'fresh' => [SourceEntry::fresh(SourceName::Weather, SourcePayloads::dailyWeather(), $fetchedAt), [true, false, '09:00', '09:00'], FailureOutcome::Stale];
        yield 'stale' => [SourceEntry::stale(SourceName::Weather, SourcePayloads::dailyWeather(), $fetchedAt, $failure, $attemptedAt), [true, true, '09:00', '12:00'], FailureOutcome::Stale];
        yield 'missing' => [SourceEntry::missing(SourceName::Weather, $failure, $attemptedAt), [false, true, null, '12:00'], FailureOutcome::Missing];
        yield 'not applicable' => [SourceEntry::notApplicable(SourceName::Weather), [false, false, null, null], FailureOutcome::Missing];
    }
}
