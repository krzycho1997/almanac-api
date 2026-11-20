<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source\Weather;

use App\Briefing\Model\WeatherKind;
use App\Briefing\Source\Weather\WeatherHorizon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class WeatherHorizonTest extends TestCase
{
    private const string KIRITIMATI = 'Pacific/Kiritimati';
    private const string NOW = '2026-11-21 05:00:00';

    #[DataProvider('days')]
    public function testCountsDaysFromTodayInUtcWhateverTheClocksZone(string $date, bool $covered, WeatherKind $kind): void
    {
        // Given
        $horizon = new WeatherHorizon(new MockClock(self::NOW, self::KIRITIMATI));
        $day = new \DateTimeImmutable($date);

        // When
        $result = [$horizon->covers($day), $horizon->kindFor($day)];

        // Then
        self::assertSame([$covered, $kind], $result);
    }

    /**
     * @return iterable<string, array{string, bool, WeatherKind}>
     */
    public static function days(): iterable
    {
        yield 'six days before today in UTC' => ['2026-11-14', true, WeatherKind::Final];
        yield 'five days before today in UTC' => ['2026-11-15', true, WeatherKind::Provisional];
        yield 'yesterday in UTC' => ['2026-11-19', true, WeatherKind::Provisional];
        yield 'today in UTC, tomorrow in Kiritimati' => ['2026-11-20', true, WeatherKind::Forecast];
        yield 'two weeks after today in UTC' => ['2026-12-04', true, WeatherKind::Forecast];
        yield 'two weeks after today in Kiritimati' => ['2026-12-05', false, WeatherKind::Forecast];
    }
}
