<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Validator;

use App\Briefing\Validator\BriefingDayText;
use App\Tests\Support\ValidatorWithClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BriefingDayTextTest extends TestCase
{
    #[DataProvider('days')]
    public function testReadsTheDayInTheTimezoneOfTheClock(string $timezone, string $day, bool $accepted): void
    {
        // Given
        $validator = ValidatorWithClock::create(new MockClock(new \DateTimeImmutable('2026-11-21 10:00', new \DateTimeZone($timezone))));

        // When
        $violations = $validator->validate($day, new BriefingDayText());

        // Then
        self::assertSame($accepted, 0 === $violations->count());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function days(): iterable
    {
        yield 'last day east of UTC' => ['Pacific/Kiritimati', '2026-12-06', true];
        yield 'day after the last east of UTC' => ['Pacific/Kiritimati', '2026-12-07', false];
        yield 'first APOD west of UTC' => ['Pacific/Pago_Pago', '1995-06-16', true];
        yield 'day before the first APOD west of UTC' => ['Pacific/Pago_Pago', '1995-06-15', false];
        yield 'not a calendar day, left to the date constraint' => ['UTC', '2024-02-30', true];
        yield 'day zero before the first APOD, left to the date constraint' => ['Pacific/Pago_Pago', '1995-06-00', true];
    }
}
