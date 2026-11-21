<?php

declare(strict_types=1);

namespace App\Briefing\Source\OpenMeteo;

use App\Briefing\BriefingDay;
use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\Exception\PayloadDefect;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\WeatherKind;

final readonly class OpenMeteoMapper
{
    private const string DAILY_FIELD = 'daily';
    private const string TIME_FIELD = 'daily.time';
    private const string CODE_FIELD = 'daily.weatherCode';
    private const string TEMPERATURE_MIN_FIELD = 'daily.temperatureMin';
    private const string TEMPERATURE_MAX_FIELD = 'daily.temperatureMax';
    private const string PRECIPITATION_FIELD = 'daily.precipitationSum';
    private const string WIND_FIELD = 'daily.windSpeedMax';
    private const string DAYLIGHT_FIELD = 'daily.daylightDuration';
    private const string SUNRISE_FIELD = 'daily.sunrise';
    private const string SUNSET_FIELD = 'daily.sunset';
    private const float COLDEST = -100.0;
    private const float HOTTEST = 70.0;
    private const float NONE = 0.0;
    private const float WETTEST = 2000.0;
    private const float WINDIEST = 500.0;
    private const float WHOLE_DAY = 86400.0;
    private const int FIRST_RECORDED_TIME = -946771200 - OpenMeteoApiResponse::EASTERNMOST_OFFSET;
    private const int LAST_PLAUSIBLE_TIME = 4102444800;
    private const int SECONDS_BEFORE_THE_DAY = 86400;
    private const int SECONDS_AFTER_THE_DAY = 172800;
    private const int SMALLEST_CODE = 0;
    private const int LARGEST_CODE = 99;
    private const string ATTRIBUTION_NAME = 'Open-Meteo';
    private const string ATTRIBUTION_URL = 'https://open-meteo.com/';
    private const string LICENSE = 'CC BY 4.0';
    private const string LICENSE_URL = 'https://creativecommons.org/licenses/by/4.0/';
    private const string COPERNICUS_NOTICE = 'Generated using Copernicus Climate Change Service information';

    public function map(OpenMeteoApiResponse $response, WeatherKind $kind, string $day): DailyWeather
    {
        $daily = self::dailyFor($response, $day);
        $start = $daily->time[0];
        $daylight = self::measure($daily->daylightDuration[0] ?? null, self::NONE, self::WHOLE_DAY, self::DAYLIGHT_FIELD);
        [$sunrise, $sunset] = self::sunTimes(self::sunTime($daily->sunrise[0] ?? null, $start, self::SUNRISE_FIELD), self::sunTime($daily->sunset[0] ?? null, $start, self::SUNSET_FIELD), $daylight, new \DateTimeZone($response->timezone));

        return new DailyWeather(
            kind: $kind,
            timezone: $response->timezone,
            temperatureMin: self::temperature($daily->temperatureMin[0] ?? null, self::TEMPERATURE_MIN_FIELD),
            temperatureMax: self::highest($daily),
            precipitationSum: self::measure($daily->precipitationSum[0] ?? null, self::NONE, self::WETTEST, self::PRECIPITATION_FIELD),
            windSpeedMax: self::measure($daily->windSpeedMax[0] ?? null, self::NONE, self::WINDIEST, self::WIND_FIELD),
            weatherCode: self::code($daily->weatherCode[0] ?? null),
            sunrise: $sunrise,
            sunset: $sunset,
            daylightDuration: self::wholeSeconds($daylight),
            attribution: self::attributionFor($kind),
        );
    }

    private static function dailyFor(OpenMeteoApiResponse $response, string $day): OpenMeteoDaily
    {
        $start = $response->daily?->time[0] ?? null;

        if (null === $start) {
            throw InvalidUpstreamPayloadException::at(self::DAILY_FIELD, PayloadDefect::NoEntries, 'Open-Meteo answered without a daily summary.');
        }

        if ($day === self::localDay(self::plausibleTime($start), $response->offsetSeconds())) {
            return self::withSomeMeasure($response->daily);
        }

        throw InvalidUpstreamPayloadException::at(self::TIME_FIELD, PayloadDefect::OtherDate, 'Open-Meteo answered for another date.');
    }

    private static function plausibleTime(int $time): int
    {
        if (self::isBetween($time, self::FIRST_RECORDED_TIME, self::LAST_PLAUSIBLE_TIME)) {
            return $time;
        }

        throw InvalidUpstreamPayloadException::at(self::TIME_FIELD, PayloadDefect::InvalidValue, 'Open-Meteo gave a day outside the recorded years.');
    }

    private static function localDay(int $time, int $utcOffset): string
    {
        return gmdate(BriefingDay::FORMAT, $time + $utcOffset);
    }

    private static function withSomeMeasure(OpenMeteoDaily $daily): OpenMeteoDaily
    {
        $measures = [$daily->weatherCode[0] ?? null, $daily->temperatureMin[0] ?? null, $daily->temperatureMax[0] ?? null, $daily->precipitationSum[0] ?? null, $daily->windSpeedMax[0] ?? null];

        if ([] === array_filter($measures, static fn (int|float|null $measure): bool => null !== $measure)) {
            throw InvalidUpstreamPayloadException::at(self::DAILY_FIELD, PayloadDefect::NoEntries, 'Open-Meteo has no measure for the day yet.');
        }

        return $daily;
    }

    private static function code(?int $code): ?int
    {
        if (null === $code) {
            return null;
        }

        if (self::isBetween($code, self::SMALLEST_CODE, self::LARGEST_CODE)) {
            return $code;
        }

        throw InvalidUpstreamPayloadException::at(self::CODE_FIELD, PayloadDefect::InvalidValue, 'Open-Meteo gave a weather code outside the WMO table.');
    }

    private static function highest(OpenMeteoDaily $daily): ?float
    {
        $highest = self::temperature($daily->temperatureMax[0] ?? null, self::TEMPERATURE_MAX_FIELD);

        if (self::isBelow($highest, $daily->temperatureMin[0] ?? null)) {
            throw InvalidUpstreamPayloadException::at(self::TEMPERATURE_MAX_FIELD, PayloadDefect::InvalidValue, 'Open-Meteo gave a highest temperature below the lowest.');
        }

        return $highest;
    }

    private static function isBelow(int|float|null $value, int|float|null $other): bool
    {
        if (null === $value) {
            return false;
        }

        if (null === $other) {
            return false;
        }

        return $value < $other;
    }

    private static function temperature(?float $value, string $field): ?float
    {
        return self::measure($value, self::COLDEST, self::HOTTEST, $field);
    }

    private static function measure(?float $value, float $lowest, float $highest, string $field): ?float
    {
        if (null === $value) {
            return null;
        }

        if (self::isBetween($value, $lowest, $highest)) {
            return $value;
        }

        throw InvalidUpstreamPayloadException::at($field, PayloadDefect::InvalidValue, "Open-Meteo gave a value outside {$lowest}..{$highest}.");
    }

    private static function isBetween(int|float $value, int|float $lowest, int|float $highest): bool
    {
        if ($value < $lowest) {
            return false;
        }

        return $value <= $highest;
    }

    private static function wholeSeconds(?float $seconds): ?int
    {
        if (null === $seconds) {
            return null;
        }

        return (int) round($seconds);
    }

    private static function sunTime(?int $time, int $start, string $field): ?int
    {
        if (null === $time) {
            return null;
        }

        if (self::isBetween($time, $start - self::SECONDS_BEFORE_THE_DAY, $start + self::SECONDS_AFTER_THE_DAY)) {
            return $time;
        }

        throw InvalidUpstreamPayloadException::at($field, PayloadDefect::InvalidValue, 'Open-Meteo gave a sun time far from the day.');
    }

    /**
     * @return array{?string, ?string}
     */
    private static function sunTimes(?int $sunrise, ?int $sunset, ?float $daylight, \DateTimeZone $zone): array
    {
        if (self::isBelow($sunset, $sunrise)) {
            throw InvalidUpstreamPayloadException::at(self::SUNSET_FIELD, PayloadDefect::InvalidValue, 'Open-Meteo gave a sunset before the sunrise.');
        }

        if (self::isOrdinaryDay($sunrise, $sunset, $daylight)) {
            return [self::localTime($sunrise, $zone), self::localTime($sunset, $zone)];
        }

        return [null, null];
    }

    /**
     * @phpstan-assert-if-true int $sunrise
     * @phpstan-assert-if-true int $sunset
     */
    private static function isOrdinaryDay(?int $sunrise, ?int $sunset, ?float $daylight): bool
    {
        if (null === $sunrise) {
            return false;
        }

        if (null === $sunset) {
            return false;
        }

        if (\in_array($daylight, [self::NONE, self::WHOLE_DAY], true)) {
            return false;
        }

        return $sunrise < $sunset;
    }

    private static function localTime(int $timestamp, \DateTimeZone $zone): string
    {
        return \DateTimeImmutable::createFromTimestamp($timestamp)->setTimezone($zone)->format(\DATE_ATOM);
    }

    private static function attributionFor(WeatherKind $kind): Attribution
    {
        return new Attribution(self::ATTRIBUTION_NAME, self::ATTRIBUTION_URL, self::LICENSE, self::LICENSE_URL, true, self::noticeFor($kind));
    }

    private static function noticeFor(WeatherKind $kind): ?string
    {
        return match ($kind) {
            WeatherKind::Final => self::COPERNICUS_NOTICE,
            WeatherKind::Provisional, WeatherKind::Forecast => null,
        };
    }
}
