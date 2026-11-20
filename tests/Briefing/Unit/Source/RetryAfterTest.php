<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source;

use App\Briefing\Source\RetryAfter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryAfterTest extends TestCase
{
    private const string NOW = '2026-11-20T12:00:00+00:00';
    private const string A_DAY_LATER = '2026-11-21T12:00:00+00:00';
    private const string WITH_MICROSECONDS = 'Y-m-d\TH:i:s.uP';
    private const string FAR_EAST_TIMEZONE = 'Pacific/Kiritimati';

    #[DataProvider('retryAfterValues')]
    public function testTurnsRetryAfterIntoATime(string $retryAfter, ?string $expectedRetryAt): void
    {
        // When
        $retryAt = RetryAfter::parse($retryAfter, new \DateTimeImmutable(self::NOW));

        // Then
        self::assertSame($expectedRetryAt, $retryAt?->format(\DateTimeInterface::ATOM));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function retryAfterValues(): iterable
    {
        yield 'seconds' => ['3600', '2026-11-20T13:00:00+00:00'];
        yield 'zero seconds' => ['0', self::NOW];
        yield 'seconds with surrounding spaces' => ['  120  ', '2026-11-20T12:02:00+00:00'];
        yield 'seconds beyond a day' => ['99999999999999999999', self::A_DAY_LATER];
        yield 'IMF-fixdate' => ['Fri, 20 Nov 2026 14:30:00 GMT', '2026-11-20T14:30:00+00:00'];
        yield 'obsolete RFC 850 date' => ['Friday, 20-Nov-26 14:30:00 GMT', '2026-11-20T14:30:00+00:00'];
        yield 'obsolete asctime date' => ['Fri Nov 20 14:30:00 2026', '2026-11-20T14:30:00+00:00'];
        yield 'date in the past' => ['Thu, 01 Jan 2026 00:00:00 GMT', self::NOW];
        yield 'date beyond a day' => ['Fri, 01 Jan 2027 00:00:00 GMT', self::A_DAY_LATER];
        yield 'negative seconds' => ['-5', null];
        yield 'fractional seconds' => ['1.5', null];
        yield 'wrong weekday' => ['Mon, 20 Nov 2026 14:30:00 GMT', null];
        yield 'day after the end of the month' => ['Mon, 31 Nov 2026 14:30:00 GMT', null];
        yield 'hour beyond the day' => ['Fri, 20 Nov 2026 25:30:00 GMT', null];
        yield 'date in another format' => ['2026-11-20T14:30:00Z', null];
        yield 'not a date' => ['soon', null];
        yield 'empty' => ['', null];
    }

    public function testReadsAnAsctimeDateWithAPaddedDay(): void
    {
        // When
        $retryAt = RetryAfter::parse('Tue Dec  1 14:30:00 2026', new \DateTimeImmutable('2026-12-01T12:00:00+00:00'));

        // Then
        self::assertSame('2026-12-01T14:30:00+00:00', $retryAt?->format(\DateTimeInterface::ATOM));
    }

    #[DataProvider('retryAfterValuesFromAnotherTimezone')]
    public function testGivesTheTimeInUtcWhateverTheTimezoneOfNow(string $retryAfter, string $expectedRetryAt): void
    {
        // When
        $retryAt = RetryAfter::parse($retryAfter, new \DateTimeImmutable('2026-11-20T13:00:00+01:00'));

        // Then
        self::assertSame($expectedRetryAt, $retryAt?->format(\DateTimeInterface::ATOM));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function retryAfterValuesFromAnotherTimezone(): iterable
    {
        yield 'seconds' => ['120', '2026-11-20T12:02:00+00:00'];
        yield 'date in the past' => ['Thu, 01 Jan 2026 00:00:00 GMT', self::NOW];
        yield 'date beyond a day' => ['Fri, 01 Jan 2027 00:00:00 GMT', self::A_DAY_LATER];
    }

    public function testReadsAnHttpDateAsAnExactGmtTimeWhateverTheDefaultTimezone(): void
    {
        // Given
        $defaultTimezone = date_default_timezone_get();
        date_default_timezone_set(self::FAR_EAST_TIMEZONE);

        try {
            // When
            $retryAt = RetryAfter::parse('Fri, 20 Nov 2026 14:30:00 GMT', new \DateTimeImmutable(self::NOW));
        } finally {
            date_default_timezone_set($defaultTimezone);
        }

        // Then
        self::assertSame('2026-11-20T14:30:00.000000+00:00', $retryAt?->format(self::WITH_MICROSECONDS));
    }
}
