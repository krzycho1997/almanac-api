<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Source\Weather;

use App\Briefing\Exception\PayloadDefect;
use App\Briefing\FailureCause;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\WeatherKind;
use App\Briefing\Source\Weather\WeatherSource;
use App\Briefing\SourceResult;
use App\Briefing\SourceStatus;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\InteractsWithTestServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;

final class OpenMeteoClientTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithTestServices;

    private const string NOW = '2026-11-20 12:00:00 UTC';
    private const string QUERY = '&daily=weather_code%2Ctemperature_2m_max%2Ctemperature_2m_min%2Cprecipitation_sum%2Cwind_speed_10m_max%2Csunrise%2Csunset%2Cdaylight_duration&timezone=auto&timeformat=unixtime&temperature_unit=celsius&wind_speed_unit=kmh&precipitation_unit=mm';
    private const string PAYLOAD_ERROR = 'InvalidUpstreamPayloadException';

    #[DataProvider('days')]
    public function testCoversDaysUpToTwoWeeksAhead(string $date, bool $covered): void
    {
        // Given
        self::mockTime(self::NOW);

        // When
        $covers = self::source()->client()->covers(new \DateTimeImmutable($date));

        // Then
        self::assertSame($covered, $covers);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function days(): iterable
    {
        yield 'day before the recorded years' => ['1939-12-31', false];
        yield 'first recorded day' => ['1940-01-01', true];
        yield 'first picture' => ['1995-06-16', true];
        yield 'today' => ['2026-11-20', true];
        yield 'two weeks ahead' => ['2026-12-04', true];
        yield 'fifteen days ahead' => ['2026-12-05', false];
    }

    #[DataProvider('farEastDays')]
    public function testCountsTheWindowInUtcWhateverTheClocksZone(string $date, bool $covered): void
    {
        // Given
        self::mockTime(new \DateTimeImmutable('2026-11-21 05:00:00', new \DateTimeZone('Pacific/Kiritimati')));

        // When
        $covers = self::source()->client()->covers(new \DateTimeImmutable($date));

        // Then
        self::assertSame($covered, $covers);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function farEastDays(): iterable
    {
        yield 'two weeks after the day in UTC' => ['2026-12-04', true];
        yield 'two weeks after the day in Kiritimati' => ['2026-12-05', false];
    }

    #[DataProvider('endpoints')]
    public function testAsksTheArchiveForOldDaysAndTheForecastForTheRest(string $date, UpstreamMock $scenario, string $expectedUrl, WeatherKind $expectedKind): void
    {
        // Given
        self::mockTime(self::NOW);
        self::upstream()->respondWith($scenario);

        // When
        $result = self::fetch($date, Location::fromCoordinates(52.2297, 21.0122));

        // Then
        self::assertSame($expectedUrl.self::QUERY, self::upstream()->served()[0]->getRequestUrl());
        self::assertInstanceOf(DailyWeather::class, $result->payload);
        self::assertSame($expectedKind, $result->payload->kind);
    }

    /**
     * @return iterable<string, array{string, UpstreamMock, string, WeatherKind}>
     */
    public static function endpoints(): iterable
    {
        yield 'six days ago' => ['2026-11-14', UpstreamMock::create()->archiveOn('2026-11-14'), 'https://archive-api.open-meteo.com/v1/archive?latitude=52.23&longitude=21.01&start_date=2026-11-14&end_date=2026-11-14', WeatherKind::Final];
        yield 'five days ago' => ['2026-11-15', UpstreamMock::create()->forecastOn('2026-11-15'), 'https://api.open-meteo.com/v1/forecast?latitude=52.23&longitude=21.01&start_date=2026-11-15&end_date=2026-11-15', WeatherKind::Provisional];
        yield 'two weeks ahead' => ['2026-12-04', UpstreamMock::create()->forecastOn('2026-12-04'), 'https://api.open-meteo.com/v1/forecast?latitude=52.23&longitude=21.01&start_date=2026-12-04&end_date=2026-12-04', WeatherKind::Forecast];
    }

    public function testSendsCoordinatesWithTwoDecimalPlaces(): void
    {
        // Given
        self::mockTime(self::NOW);
        self::upstream()->respondWith(UpstreamMock::create()->archiveOn('2024-03-15'));

        // When
        self::fetch('2024-03-15', Location::fromCoordinates(-90.0, 180.0));

        // Then
        self::assertStringContainsString('?latitude=-90.00&longitude=180.00&', self::upstream()->served()[0]->getRequestUrl());
    }

    #[DataProvider('recordedDays')]
    public function testMapsRecordedDays(string $date, UpstreamMock $scenario, DailyWeather $expected): void
    {
        // Given
        self::mockTime(self::NOW);
        self::upstream()->respondWith($scenario);

        // When
        $result = self::fetch($date, Location::fromCoordinates(48.86, 2.35));

        // Then
        self::assertEquals($expected, $result->payload);
    }

    /**
     * @return iterable<string, array{string, UpstreamMock, DailyWeather}>
     */
    public static function recordedDays(): iterable
    {
        yield 'Paris in the archive, winter' => ['2024-03-15', UpstreamMock::create()->archive()->fixture('open_meteo/archive-2024-03-15.json'), self::weather(WeatherKind::Final, 'Europe/Paris', 11.1, 16.2, 9.7, 28.9, 61, '2024-03-15T07:03:30+01:00', '2024-03-15T18:56:37+01:00', 42786)];
        yield 'Paris in the archive, summer recorded in winter' => ['2024-07-01', UpstreamMock::create()->archive()->fixture('open_meteo/archive-summer-2024-07-01.json'), self::weather(WeatherKind::Final, 'Europe/Paris', 13.9, 19.9, 2.0, 17.6, 53, '2024-07-01T05:51:31+02:00', '2024-07-01T21:57:45+02:00', 57968)];
        yield 'Paris in the forecast' => ['2026-11-20', UpstreamMock::create()->forecast()->fixture('open_meteo/forecast-2026-11-20.json'), self::weather(WeatherKind::Forecast, 'Europe/Paris', 8.9, 12.8, 1.0, 25.8, 80, '2026-11-20T08:07:00+01:00', '2026-11-20T17:04:54+01:00', 32272)];
        yield 'sunset after midnight in Tromsø' => ['2024-05-17', UpstreamMock::create()->archive()->fixture('open_meteo/archive-sunset-after-midnight-2024-05-17.json'), self::weather(WeatherKind::Final, 'Europe/Oslo', 5.5, 18.7, 0.1, 15.8, 51, '2024-05-17T01:10:53+02:00', '2024-05-18T00:22:49+02:00', 84324)];
        yield 'polar day' => ['2024-06-21', UpstreamMock::create()->archive()->fixture('open_meteo/archive-polar-day-2024-06-21.json'), self::weather(WeatherKind::Final, 'Arctic/Longyearbyen', 5.0, 9.3, 0.0, 11.4, 3, null, null, 86400)];
        yield 'Point Nemo in an Etc zone' => ['2024-03-15', UpstreamMock::create()->archive()->fixture('open_meteo/archive-point-nemo-2024-03-15.json'), self::weather(WeatherKind::Final, 'Etc/GMT+8', 9.9, 11.4, 0.0, 30.8, 3, '2024-03-15T06:09:03-08:00', '2024-03-15T18:34:39-08:00', 44748)];
        yield 'North Pole in polar night' => ['2024-03-15', UpstreamMock::create()->archive()->fixture('open_meteo/archive-north-pole-2024-03-15.json'), self::weather(WeatherKind::Final, 'Etc/GMT', -28.3, -26.4, 0.0, 32.8, 3, null, null, 0)];
        yield 'polar night' => ['2024-12-21', UpstreamMock::create()->archive()->fixture('open_meteo/archive-polar-night-2024-12-21.json'), self::weather(WeatherKind::Final, 'Arctic/Longyearbyen', -13.9, -6.1, 0.0, 13.0, 3, null, null, 0)];
    }

    #[DataProvider('failures')]
    public function testReportsADayWithoutWeather(string $date, UpstreamMock $scenario, FailureKind $kind, ?FailureCause $cause): void
    {
        // Given
        self::mockTime(self::NOW);
        self::upstream()->respondWith($scenario);

        // When
        $result = self::fetch($date, Location::fromCoordinates(21.30, -157.85));

        // Then
        self::assertSame(SourceStatus::Failed, $result->status);
        self::assertSame($kind, $result->failureKind);
        self::assertEquals($cause, $result->cause);
    }

    /**
     * @return iterable<string, array{string, UpstreamMock, FailureKind, ?FailureCause}>
     */
    public static function failures(): iterable
    {
        yield 'day beyond the horizon of the place' => ['2026-12-05', UpstreamMock::create()->forecast()->fixture('open_meteo/forecast-beyond-the-horizon-2026-12-05.json'), FailureKind::InvalidPayload, new FailureCause(self::PAYLOAD_ERROR, ['daily'], PayloadDefect::NoEntries)];
        yield 'date out of range' => ['2026-12-05', UpstreamMock::create()->forecast()->fixture('open_meteo/forecast-out-of-range.json')->status(Response::HTTP_BAD_REQUEST), FailureKind::Rejected, null];
        yield 'empty answer' => ['2026-12-05', UpstreamMock::create()->forecast(), FailureKind::InvalidPayload, new FailureCause(self::PAYLOAD_ERROR, ['timezone', 'utcOffset'], PayloadDefect::WrongShape)];
        yield 'answer for another day' => ['2026-12-05', UpstreamMock::create()->forecastOn('2026-12-04'), FailureKind::InvalidPayload, new FailureCause(self::PAYLOAD_ERROR, ['daily.time'], PayloadDefect::OtherDate)];
        yield 'text instead of a temperature' => ['2026-12-05', UpstreamMock::create()->forecast()->json('{"timezone":"UTC","utc_offset_seconds":0,"daily":{"time":[1796428800],"temperature_2m_max":["warm"]}}'), FailureKind::InvalidPayload, new FailureCause(self::PAYLOAD_ERROR, ['daily.temperatureMax[0]'], PayloadDefect::WrongShape)];
        yield 'infinite temperature' => ['2026-12-05', UpstreamMock::create()->forecast()->json('{"timezone":"UTC","utc_offset_seconds":0,"daily":{"time":[1796428800],"temperature_2m_max":[1e999]}}'), FailureKind::InvalidPayload, new FailureCause(self::PAYLOAD_ERROR, ['daily.temperatureMax'], PayloadDefect::InvalidValue)];
        yield 'no UTC offset' => ['2026-12-05', UpstreamMock::create()->forecast()->json('{"timezone":"UTC","daily":{"time":[1796428800],"weather_code":[3]}}'), FailureKind::InvalidPayload, new FailureCause(self::PAYLOAD_ERROR, ['utcOffset'], PayloadDefect::WrongShape)];
        yield 'UTC offset beyond any zone' => ['2026-12-05', UpstreamMock::create()->forecast()->json('{"timezone":"UTC","utc_offset_seconds":9223372036854775807,"daily":{"time":[1796428800],"weather_code":[3]}}'), FailureKind::InvalidPayload, new FailureCause(self::PAYLOAD_ERROR, ['utcOffset'], PayloadDefect::InvalidValue)];
        yield 'UTC offset with a fraction' => ['2026-12-05', UpstreamMock::create()->forecast()->json('{"timezone":"UTC","utc_offset_seconds":3600.5,"daily":{"time":[1796428800],"weather_code":[3]}}'), FailureKind::InvalidPayload, new FailureCause(self::PAYLOAD_ERROR, ['utcOffset'], PayloadDefect::InvalidValue)];
        yield 'unknown time zone' => ['2026-12-05', UpstreamMock::create()->forecast()->json('{"timezone":"Mars/Olympus","utc_offset_seconds":0,"daily":{"time":[1796428800],"weather_code":[3]}}'), FailureKind::InvalidPayload, new FailureCause(self::PAYLOAD_ERROR, ['timezone'], PayloadDefect::InvalidValue)];
    }

    private static function weather(WeatherKind $kind, string $timezone, float $lowest, float $highest, float $precipitation, float $wind, int $code, ?string $sunrise, ?string $sunset, int $daylight): DailyWeather
    {
        return new DailyWeather($kind, $timezone, $lowest, $highest, $precipitation, $wind, $code, $sunrise, $sunset, $daylight, new Attribution('Open-Meteo', 'https://open-meteo.com/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', true, WeatherKind::Final === $kind ? 'Generated using Copernicus Climate Change Service information' : null));
    }

    private static function fetch(string $date, Location $location): SourceResult
    {
        $source = self::source();

        return $source->client()->request(new \DateTimeImmutable($date), $location)->resolve($source->name());
    }

    private static function source(): WeatherSource
    {
        return self::testService(WeatherSource::class);
    }

    private static function upstream(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }
}
