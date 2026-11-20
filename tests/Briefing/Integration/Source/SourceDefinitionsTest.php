<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Source;

use App\Briefing\Location;
use App\Briefing\SnapshotScope;
use App\Briefing\Source\Apod\ApodSource;
use App\Briefing\Source\OnThisDay\OnThisDaySource;
use App\Briefing\Source\Source;
use App\Briefing\Source\Weather\WeatherSource;
use App\Briefing\SourceName;
use App\Tests\Support\InteractsWithTestServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SourceDefinitionsTest extends KernelTestCase
{
    use InteractsWithTestServices;

    private const string DAY = '2026-11-17';
    private const string WARSAW = 'Europe/Warsaw';

    /**
     * @param class-string<Source> $definition
     */
    #[DataProvider('refreshIntervals')]
    public function testTellsHowLongItsDataStaysFresh(string $definition, ?int $expected): void
    {
        // When
        $refreshAfter = self::testService($definition)->refreshAfter();

        // Then
        self::assertSame($expected, self::seconds($refreshAfter));
    }

    /**
     * @return iterable<string, array{class-string<Source>, ?int}>
     */
    public static function refreshIntervals(): iterable
    {
        yield 'apod is final once fetched' => [ApodSource::class, null];
        yield 'on this day is final once fetched' => [OnThisDaySource::class, null];
        yield 'weather refreshes hourly' => [WeatherSource::class, 3600];
    }

    /**
     * @param class-string<Source> $definition
     */
    #[DataProvider('scopes')]
    public function testKeysItsSnapshotByWhatTheDataDependsOn(string $definition, Location $location, SourceName $source, string $key, ?string $date): void
    {
        // Given
        $day = new \DateTimeImmutable(self::DAY, new \DateTimeZone(self::WARSAW));

        // When
        $scope = self::testService($definition)->scopeFor($day, $location);

        // Then
        self::assertSame([$source, $key, $date], [$scope->source, $scope->key, $scope->date?->format('Y-m-d H:i e')]);
    }

    /**
     * @return iterable<string, array{class-string<Source>, Location, SourceName, string, ?string}>
     */
    public static function scopes(): iterable
    {
        $warsaw = Location::fromCoordinates(52.23, 21.01);

        yield 'apod by day' => [ApodSource::class, $warsaw, SourceName::Apod, '2026-11-17', '2026-11-17 00:00 Europe/Warsaw'];
        yield 'apod ignores the place' => [ApodSource::class, Location::fromCoordinates(-33.87, 151.21), SourceName::Apod, '2026-11-17', '2026-11-17 00:00 Europe/Warsaw'];
        yield 'on this day by month and day only' => [OnThisDaySource::class, $warsaw, SourceName::OnThisDay, '11-17', null];
        yield 'weather by day and place' => [WeatherSource::class, $warsaw, SourceName::Weather, '2026-11-17@52.23,21.01', '2026-11-17 00:00 Europe/Warsaw'];
        yield 'weather keeps two decimals' => [WeatherSource::class, Location::fromCoordinates(52.2, 21.0), SourceName::Weather, '2026-11-17@52.20,21.00', '2026-11-17 00:00 Europe/Warsaw'];
        yield 'weather south and west' => [WeatherSource::class, Location::fromCoordinates(-89.99, -179.99), SourceName::Weather, '2026-11-17@-89.99,-179.99', '2026-11-17 00:00 Europe/Warsaw'];
        yield 'weather without negative zero' => [WeatherSource::class, Location::fromCoordinates(-0.001, -0.004), SourceName::Weather, '2026-11-17@0.00,0.00', '2026-11-17 00:00 Europe/Warsaw'];
        yield 'weather on the date line' => [WeatherSource::class, Location::fromCoordinates(90.0, -180.0), SourceName::Weather, '2026-11-17@90.00,180.00', '2026-11-17 00:00 Europe/Warsaw'];
    }

    /**
     * @param class-string<Source> $definition
     */
    #[DataProvider('singleDigitDays')]
    public function testPadsSingleDigitMonthsAndDays(string $definition, string $key): void
    {
        // When
        $scope = self::testService($definition)->scopeFor(new \DateTimeImmutable('2026-01-05'), Location::fromCoordinates(5.0, 7.0));

        // Then
        self::assertSame($key, $scope->key);
    }

    /**
     * @return iterable<string, array{class-string<Source>, string}>
     */
    public static function singleDigitDays(): iterable
    {
        yield 'apod' => [ApodSource::class, '2026-01-05'];
        yield 'on this day' => [OnThisDaySource::class, '01-05'];
        yield 'weather' => [WeatherSource::class, '2026-01-05@5.00,7.00'];
    }

    public function testSharesTheWeatherSnapshotOfTheDefaultLocation(): void
    {
        // Given
        $day = new \DateTimeImmutable(self::DAY);
        $weather = self::testService(WeatherSource::class);

        // When
        $default = $weather->scopeFor($day, Location::defaultAt(52.23, 21.01, 'Warsaw'));
        $explicit = $weather->scopeFor($day, Location::fromCoordinates(52.23, 21.01));

        // Then
        self::assertSame($explicit->key, $default->key);
    }

    public function testKeepsTheLongestKeyWithinTheColumn(): void
    {
        // When
        $scope = self::testService(WeatherSource::class)->scopeFor(new \DateTimeImmutable(self::DAY), Location::fromCoordinates(-89.99, -179.99));

        // Then
        self::assertLessThanOrEqual(SnapshotScope::LONGEST_KEY, mb_strlen($scope->key));
    }

    private static function seconds(?\DateInterval $interval): ?int
    {
        if (null === $interval) {
            return null;
        }

        return new \DateTimeImmutable('@0')->add($interval)->getTimestamp();
    }
}
