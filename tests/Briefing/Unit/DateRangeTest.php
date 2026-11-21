<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\DateRange;
use App\Briefing\Exception\InvalidDateRangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    /**
     * @param list<string> $days
     */
    #[DataProvider('ranges')]
    public function testWalksEveryDayWithBothEnds(string $from, string $to, string $timezone, array $days): void
    {
        // Given
        $zone = new \DateTimeZone($timezone);
        $range = DateRange::between(new \DateTimeImmutable($from, $zone), new \DateTimeImmutable($to, $zone));

        // When
        $walked = array_map(static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d H:i e'), iterator_to_array($range, false));

        // Then
        self::assertSame($days, $walked);
        self::assertCount(\count($days), $range);
    }

    /**
     * @return iterable<string, array{string, string, string, list<string>}>
     */
    public static function ranges(): iterable
    {
        yield 'one day' => ['2026-11-20', '2026-11-20', 'UTC', ['2026-11-20 00:00 UTC']];
        yield 'over a month end' => ['2026-11-30', '2026-12-01', 'UTC', ['2026-11-30 00:00 UTC', '2026-12-01 00:00 UTC']];
        yield 'over a leap day' => ['2024-02-28', '2024-03-01', 'UTC', ['2024-02-28 00:00 UTC', '2024-02-29 00:00 UTC', '2024-03-01 00:00 UTC']];
        yield 'over the spring clock change' => ['2026-03-28', '2026-03-30', 'Europe/Warsaw', ['2026-03-28 00:00 Europe/Warsaw', '2026-03-29 00:00 Europe/Warsaw', '2026-03-30 00:00 Europe/Warsaw']];
        yield 'over the autumn clock change' => ['2026-10-24', '2026-10-26', 'Europe/Warsaw', ['2026-10-24 00:00 Europe/Warsaw', '2026-10-25 00:00 Europe/Warsaw', '2026-10-26 00:00 Europe/Warsaw']];
        yield 'from a time of day in Warsaw' => ['2026-11-20 23:59', '2026-11-21 00:30', 'Europe/Warsaw', ['2026-11-20 00:00 Europe/Warsaw', '2026-11-21 00:00 Europe/Warsaw']];
        yield 'over a day without a midnight' => ['2018-11-03', '2018-11-05', 'America/Sao_Paulo', ['2018-11-03 00:00 America/Sao_Paulo', '2018-11-04 01:00 America/Sao_Paulo', '2018-11-05 00:00 America/Sao_Paulo']];
        yield 'from later in the day than the last date' => ['2026-11-20 18:30', '2026-11-20 06:00', 'UTC', ['2026-11-20 00:00 UTC']];
        yield 'from a time of day' => ['2026-11-20 18:30', '2026-11-21 01:00', 'UTC', ['2026-11-20 00:00 UTC', '2026-11-21 00:00 UTC']];
    }

    public function testRefusesARangeThatEndsBeforeItStarts(): void
    {
        // Then / When
        $this->expectException(InvalidDateRangeException::class);
        DateRange::between(new \DateTimeImmutable('2026-11-21'), new \DateTimeImmutable('2026-11-20'));
    }

    public function testEndsOnTheCalendarDayOfItsLastDateInTheZoneOfItsFirst(): void
    {
        // When
        $range = DateRange::between(new \DateTimeImmutable('2026-11-20', new \DateTimeZone('UTC')), new \DateTimeImmutable('2026-11-21 00:30', new \DateTimeZone('Europe/Warsaw')));

        // Then
        self::assertSame(['2026-11-20 00:00 UTC', '2026-11-21 00:00 UTC'], [$range->from->format('Y-m-d H:i e'), $range->to->format('Y-m-d H:i e')]);
        self::assertCount(2, $range);
    }

    public function testCountsAWholeYear(): void
    {
        // When
        $range = DateRange::between(new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2024-12-31'));

        // Then
        self::assertCount(366, $range);
    }
}
