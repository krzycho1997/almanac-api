<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source\OpenMeteo;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\Exception\PayloadDefect;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\WeatherKind;
use App\Briefing\Model\WmoCode;
use App\Briefing\Source\OpenMeteo\OpenMeteoApiResponse;
use App\Briefing\Source\OpenMeteo\OpenMeteoDaily;
use App\Briefing\Source\OpenMeteo\OpenMeteoMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenMeteoMapperTest extends TestCase
{
    private const string DAY = '2024-07-01';
    private const string PARIS = 'Europe/Paris';
    private const int WINTER_OFFSET = 3600;
    private const int MIDNIGHT = 1719788400;
    private const int SUNRISE = 1719805891;
    private const int SUNSET = 1719863865;

    public function testMapsTheDailySummaryInThePlacesOwnTimeZone(): void
    {
        // Given
        $response = new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, self::daily());

        // When
        $weather = new OpenMeteoMapper()->map($response, WeatherKind::Final, self::DAY);

        // Then
        self::assertEquals(
            new DailyWeather(WeatherKind::Final, self::PARIS, 12.5, 19.9, 0.2, 14.8, 3, '2024-07-01T05:51:31+02:00', '2024-07-01T21:57:45+02:00', 57968, new Attribution('Open-Meteo', 'https://open-meteo.com/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', true, 'Generated using Copernicus Climate Change Service information')),
            $weather,
        );
    }

    #[DataProvider('kindsWithoutReanalysis')]
    public function testKeepsTheKindItWasAskedForWithoutTheReanalysisNotice(WeatherKind $kind): void
    {
        // When
        $weather = new OpenMeteoMapper()->map(new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, self::daily()), $kind, self::DAY);

        // Then
        self::assertSame($kind, $weather->kind);
        self::assertNull($weather->attribution->notice);
    }

    /**
     * @return iterable<string, array{WeatherKind}>
     */
    public static function kindsWithoutReanalysis(): iterable
    {
        yield 'provisional' => [WeatherKind::Provisional];
        yield 'forecast' => [WeatherKind::Forecast];
    }

    public function testLeavesMissingMeasuresEmpty(): void
    {
        // Given
        $daily = new OpenMeteoDaily([self::MIDNIGHT], [42], [null], [-1.0], [null], [null]);

        // When
        $weather = new OpenMeteoMapper()->map(new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, $daily), WeatherKind::Final, self::DAY);

        // Then
        self::assertSame([-1.0, null, null, null, 42, null], [$weather->temperatureMin, $weather->temperatureMax, $weather->precipitationSum, $weather->windSpeedMax, $weather->weatherCode, $weather->daylightDuration]);
    }

    /**
     * @param list<float|null> $expected
     */
    #[DataProvider('singleMeasures')]
    public function testAcceptsADayWithASingleMeasure(OpenMeteoDaily $daily, array $expected): void
    {
        // When
        $weather = new OpenMeteoMapper()->map(new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, $daily), WeatherKind::Forecast, self::DAY);

        // Then
        self::assertSame($expected, [$weather->weatherCode, $weather->temperatureMax, $weather->temperatureMin, $weather->precipitationSum, $weather->windSpeedMax]);
    }

    /**
     * @return iterable<string, array{OpenMeteoDaily, list<float|int|null>}>
     */
    public static function singleMeasures(): iterable
    {
        yield 'weather code' => [new OpenMeteoDaily([self::MIDNIGHT], [3], [null], [null], [null], [null]), [3, null, null, null, null]];
        yield 'lowest temperature' => [new OpenMeteoDaily([self::MIDNIGHT], [null], [null], [-4.5], [null], [null]), [null, null, -4.5, null, null]];
        yield 'precipitation' => [new OpenMeteoDaily([self::MIDNIGHT], [null], [null], [null], [2.5], [null]), [null, null, null, 2.5, null]];
        yield 'wind' => [new OpenMeteoDaily([self::MIDNIGHT], [null], [null], [null], [null], [12.0]), [null, null, null, null, 12.0]];
    }

    /**
     * @param list<int|null>   $sunrise
     * @param list<int|null>   $sunset
     * @param list<float|null> $daylight
     */
    #[DataProvider('sunTimes')]
    public function testGivesSunTimesOnlyWhenTheSunRisesAndSets(array $sunrise, array $sunset, array $daylight, ?string $expectedSunrise, ?string $expectedSunset): void
    {
        // Given
        $daily = new OpenMeteoDaily([self::MIDNIGHT], [3], [20.0], [10.0], [0.0], [5.0], $sunrise, $sunset, $daylight);

        // When
        $weather = new OpenMeteoMapper()->map(new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, $daily), WeatherKind::Final, self::DAY);

        // Then
        self::assertSame([$expectedSunrise, $expectedSunset], [$weather->sunrise, $weather->sunset]);
    }

    /**
     * @return iterable<string, array{list<int|null>, list<int|null>, list<float|null>, ?string, ?string}>
     */
    public static function sunTimes(): iterable
    {
        yield 'ordinary day' => [[self::SUNRISE], [self::SUNSET], [57968.19], '2024-07-01T05:51:31+02:00', '2024-07-01T21:57:45+02:00'];
        yield 'sunset after midnight' => [[1719789053], [1719872569], [84323.62], '2024-07-01T01:10:53+02:00', '2024-07-02T00:22:49+02:00'];
        yield 'polar day' => [[self::MIDNIGHT], [self::MIDNIGHT + 86400], [86400.0], null, null];
        yield 'polar night' => [[self::MIDNIGHT], [self::MIDNIGHT], [0.0], null, null];
        yield 'no daylight between two sun times' => [[self::SUNRISE], [self::SUNSET], [0.0], null, null];
        yield 'polar night without daylight' => [[self::MIDNIGHT], [self::MIDNIGHT], [], null, null];
        yield 'missing sunrise' => [[null], [self::SUNSET], [57968.19], null, null];
        yield 'almost a whole day of daylight' => [[self::SUNRISE], [self::SUNRISE + 86399], [86399.6], '2024-07-01T05:51:31+02:00', '2024-07-02T05:51:30+02:00'];
        yield 'missing sunset' => [[self::SUNRISE], [], [57968.19], null, null];
    }

    public function testRoundsTheDaylightToWholeSeconds(): void
    {
        // Given
        $daily = new OpenMeteoDaily([self::MIDNIGHT], [3], [20.0], [10.0], [0.0], [5.0], [self::SUNRISE], [self::SUNSET], [57968.5]);

        // When
        $weather = new OpenMeteoMapper()->map(new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, $daily), WeatherKind::Final, self::DAY);

        // Then
        self::assertSame(57969, $weather->daylightDuration);
    }

    #[DataProvider('codes')]
    public function testDescribesEveryWeatherCode(WmoCode $code): void
    {
        // When
        $description = $code->description();

        // Then
        self::assertMatchesRegularExpression('/^[A-Z][a-z]+( [a-z]+)*$/', $description);
    }

    /**
     * @return iterable<string, array{WmoCode}>
     */
    public static function codes(): iterable
    {
        foreach (WmoCode::cases() as $code) {
            yield $code->name => [$code];
        }
    }

    #[DataProvider('unusableDays')]
    public function testRejectsADayWithoutWeather(OpenMeteoApiResponse $response, string $field, PayloadDefect $defect): void
    {
        // When
        $rejection = self::rejectionOf($response);

        // Then
        self::assertSame([$field], $rejection->fields());
        self::assertSame($defect, $rejection->defect());
    }

    /**
     * @return iterable<string, array{OpenMeteoApiResponse, string, PayloadDefect}>
     */
    public static function unusableDays(): iterable
    {
        yield 'no daily summary' => [new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET), 'daily', PayloadDefect::NoEntries];
        yield 'empty daily summary' => [new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, new OpenMeteoDaily()), 'daily', PayloadDefect::NoEntries];
        yield 'another day' => [new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, new OpenMeteoDaily([self::MIDNIGHT + 86400], [3])), 'daily.time', PayloadDefect::OtherDate];
        yield 'day of another offset' => [new OpenMeteoApiResponse(self::PARIS, 0, new OpenMeteoDaily([self::MIDNIGHT], [3])), 'daily.time', PayloadDefect::OtherDate];
        yield 'day beyond the horizon' => [new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, new OpenMeteoDaily([self::MIDNIGHT], [null], [null], [null], [null], [null], [self::SUNRISE], [self::SUNSET])), 'daily', PayloadDefect::NoEntries];
        yield 'infinite temperature' => [self::with(temperatureMax: \INF), 'daily.temperatureMax', PayloadDefect::InvalidValue];
        yield 'temperature not a number' => [self::with(temperatureMin: \NAN), 'daily.temperatureMin', PayloadDefect::InvalidValue];
        yield 'too hot' => [self::with(temperatureMax: 70.1), 'daily.temperatureMax', PayloadDefect::InvalidValue];
        yield 'too cold' => [self::with(temperatureMin: -100.1), 'daily.temperatureMin', PayloadDefect::InvalidValue];
        yield 'highest below lowest' => [self::with(temperatureMax: 9.9), 'daily.temperatureMax', PayloadDefect::InvalidValue];
        yield 'negative precipitation' => [self::with(precipitation: -0.1), 'daily.precipitationSum', PayloadDefect::InvalidValue];
        yield 'too much precipitation' => [self::with(precipitation: 2000.1), 'daily.precipitationSum', PayloadDefect::InvalidValue];
        yield 'negative wind' => [self::with(wind: -0.1), 'daily.windSpeedMax', PayloadDefect::InvalidValue];
        yield 'too much wind' => [self::with(wind: 500.1), 'daily.windSpeedMax', PayloadDefect::InvalidValue];
        yield 'daylight longer than a day' => [self::with(daylight: 86400.1), 'daily.daylightDuration', PayloadDefect::InvalidValue];
        yield 'day before the recorded years' => [new OpenMeteoApiResponse(self::PARIS, 0, new OpenMeteoDaily([-946821601], [3])), 'daily.time', PayloadDefect::InvalidValue];
        yield 'day after the plausible years' => [new OpenMeteoApiResponse(self::PARIS, 0, new OpenMeteoDaily([4102444801], [3])), 'daily.time', PayloadDefect::InvalidValue];
        yield 'largest integer as the day' => [new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, new OpenMeteoDaily([\PHP_INT_MAX], [3])), 'daily.time', PayloadDefect::InvalidValue];
        yield 'sunrise a day before the day' => [self::withSun(self::MIDNIGHT - 86401, self::SUNSET), 'daily.sunrise', PayloadDefect::InvalidValue];
        yield 'sunset two days after the day' => [self::withSun(self::SUNRISE, self::MIDNIGHT + 172801), 'daily.sunset', PayloadDefect::InvalidValue];
        yield 'sunset before the sunrise' => [self::withSun(self::SUNSET, self::SUNRISE), 'daily.sunset', PayloadDefect::InvalidValue];
        yield 'code above the WMO table' => [self::withCode(100), 'daily.weatherCode', PayloadDefect::InvalidValue];
        yield 'negative code' => [self::withCode(-1), 'daily.weatherCode', PayloadDefect::InvalidValue];
        yield 'negative daylight' => [self::with(daylight: -1.0), 'daily.daylightDuration', PayloadDefect::InvalidValue];
    }

    #[DataProvider('extremeDays')]
    public function testAcceptsTheEdgesOfThePlausibleRanges(OpenMeteoApiResponse $response): void
    {
        // When
        $weather = new OpenMeteoMapper()->map($response, WeatherKind::Final, self::DAY);

        // Then
        self::assertSame(WeatherKind::Final, $weather->kind);
    }

    /**
     * @return iterable<string, array{OpenMeteoApiResponse}>
     */
    public static function extremeDays(): iterable
    {
        yield 'hottest' => [self::with(temperatureMax: 70.0)];
        yield 'coldest' => [self::with(temperatureMin: -100.0)];
        yield 'same lowest and highest' => [self::with(temperatureMax: 10.0)];
        yield 'dry and still' => [self::with(precipitation: 0.0, wind: 0.0)];
        yield 'wettest and windiest' => [self::with(precipitation: 2000.0, wind: 500.0)];
        yield 'whole day of daylight' => [self::with(daylight: 86400.0)];
        yield 'sunrise a day before the day' => [self::withSun(self::MIDNIGHT - 86400, self::SUNSET)];
        yield 'sunset two days after the day' => [self::withSun(self::SUNRISE, self::MIDNIGHT + 172800)];
        yield 'clear sky' => [self::withCode(0)];
        yield 'last code of the table' => [self::withCode(99)];
    }

    private static function with(float $temperatureMax = 20.0, float $temperatureMin = 10.0, float $precipitation = 1.0, float $wind = 5.0, float $daylight = 50000.0): OpenMeteoApiResponse
    {
        return new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, new OpenMeteoDaily([self::MIDNIGHT], [3], [$temperatureMax], [$temperatureMin], [$precipitation], [$wind], [self::SUNRISE], [self::SUNSET], [$daylight]));
    }

    #[DataProvider('plausibleDays')]
    public function testAcceptsTheFirstAndLastPlausibleDays(string $timezone, int $offset, int $start, string $day): void
    {
        // When
        $weather = new OpenMeteoMapper()->map(new OpenMeteoApiResponse($timezone, $offset, new OpenMeteoDaily([$start], [3])), WeatherKind::Final, $day);

        // Then
        self::assertSame(3, $weather->weatherCode);
    }

    /**
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function plausibleDays(): iterable
    {
        yield 'first recorded day' => ['UTC', 0, -946771200, '1940-01-01'];
        yield 'first recorded day in Warsaw' => ['Europe/Warsaw', 3600, -946774800, '1940-01-01'];
        yield 'first recorded day in Kiritimati' => ['Pacific/Kiritimati', 50400, -946821600, '1940-01-01'];
        yield 'last plausible day' => ['UTC', 0, 4102444800, '2100-01-01'];
    }

    private static function withSun(int $sunrise, int $sunset): OpenMeteoApiResponse
    {
        return new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, new OpenMeteoDaily([self::MIDNIGHT], [3], [20.0], [10.0], [1.0], [5.0], [$sunrise], [$sunset], [50000.0]));
    }

    private static function withCode(int $code): OpenMeteoApiResponse
    {
        return new OpenMeteoApiResponse(self::PARIS, self::WINTER_OFFSET, new OpenMeteoDaily([self::MIDNIGHT], [$code], [20.0], [10.0], [1.0], [5.0]));
    }

    private static function rejectionOf(OpenMeteoApiResponse $response): InvalidUpstreamPayloadException
    {
        try {
            new OpenMeteoMapper()->map($response, WeatherKind::Final, self::DAY);
        } catch (InvalidUpstreamPayloadException $exception) {
            return $exception;
        }

        self::fail('The day must be rejected.');
    }

    private static function daily(): OpenMeteoDaily
    {
        return new OpenMeteoDaily([self::MIDNIGHT], [3], [19.9], [12.5], [0.2], [14.8], [self::SUNRISE], [self::SUNSET], [57968.19]);
    }
}
