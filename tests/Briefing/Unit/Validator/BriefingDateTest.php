<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Validator;

use App\Briefing\Validator\BriefingDate;
use App\Tests\Support\ValidatorWithClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BriefingDateTest extends TestCase
{
    #[DataProvider('dates')]
    public function testAcceptsDaysFromTheFirstApodToFifteenDaysAhead(string $now, string $timezone, string $date, bool $accepted): void
    {
        // Given
        $zone = new \DateTimeZone($timezone);
        $validator = ValidatorWithClock::create(new MockClock(new \DateTimeImmutable($now, $zone)));

        // When
        $violations = $validator->validate(new \DateTimeImmutable($date, $zone), new BriefingDate());

        // Then
        self::assertSame($accepted, 0 === $violations->count());
    }

    public function testNamesTheWindowInDatesWithoutTimes(): void
    {
        // Given
        $validator = ValidatorWithClock::create(new MockClock(new \DateTimeImmutable('2026-11-20 12:00')));

        // When
        $violations = $validator->validate(new \DateTimeImmutable('2026-12-06'), new BriefingDate());

        // Then
        self::assertSame(['The date must be between 1995-06-16 and 15 days after today.'], array_map(static fn ($violation): string => (string) $violation->getMessage(), [...$violations]));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function dates(): iterable
    {
        yield 'day before the first APOD' => ['2026-11-20 12:00', 'UTC', '1995-06-15', false];
        yield 'first APOD' => ['2026-11-20 12:00', 'UTC', '1995-06-16', true];
        yield 'first APOD east of UTC' => ['2026-11-20 12:00', 'Pacific/Kiritimati', '1995-06-16', true];
        yield 'first APOD west of UTC' => ['2026-11-20 12:00', 'America/Los_Angeles', '1995-06-16', true];
        yield 'today' => ['2026-11-20 12:00', 'UTC', '2026-11-20', true];
        yield 'fifteen days ahead' => ['2026-11-20 12:00', 'UTC', '2026-12-05', true];
        yield 'sixteen days ahead' => ['2026-11-20 12:00', 'UTC', '2026-12-06', false];
        yield 'fifteen days ahead just before midnight' => ['2026-11-20 23:59:59', 'UTC', '2026-12-05', true];
        yield 'fifteen days ahead in the application time zone' => ['2026-11-20 00:30', 'Pacific/Kiritimati', '2026-12-05', true];
        yield 'sixteen days ahead in the application time zone' => ['2026-11-20 00:30', 'Pacific/Kiritimati', '2026-12-06', false];
        yield 'fifteen days ahead on the night clocks go forward' => ['2026-03-29 00:30', 'Europe/Warsaw', '2026-04-13', true];
        yield 'sixteen days ahead on the night clocks go forward' => ['2026-03-29 00:30', 'Europe/Warsaw', '2026-04-14', false];
        yield 'fifteen days ahead on the night clocks go back' => ['2026-10-25 23:59', 'Europe/Warsaw', '2026-11-09', true];
        yield 'sixteen days ahead on the night clocks go back' => ['2026-10-25 23:59', 'Europe/Warsaw', '2026-11-10', false];
        yield 'day without a midnight as the last day' => ['2026-08-22 12:00', 'America/Santiago', '2026-09-06', true];
        yield 'day after the day without a midnight' => ['2026-08-22 12:00', 'America/Santiago', '2026-09-07', false];
    }
}
