<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\Recording;

use App\Briefing\SourceName;
use Symfony\Component\HttpFoundation\Response;

final class UpstreamFixtures
{
    private const string APOD = 'https://api.nasa.gov/planetary/apod?date=';
    private const string APOD_PASSTHROUGH = 'https://science.nasa.gov/wp-json/wp/v2/apod-basic/';
    private const string ON_THIS_DAY = 'https://en.wikipedia.org/api/rest_v1/feed/onthisday/';
    private const string ON_THIS_DAY_DATE = '2026-03-15';
    private const string ARCHIVE = 'https://archive-api.open-meteo.com/v1/archive?';
    private const string FORECAST = 'https://api.open-meteo.com/v1/forecast?';
    private const string COORDINATE = '%.2F';
    private const array WEATHER_QUERY = [
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,sunrise,sunset,daylight_duration',
        'timezone' => 'auto',
        'timeformat' => 'unixtime',
        'temperature_unit' => 'celsius',
        'wind_speed_unit' => 'kmh',
        'precipitation_unit' => 'mm',
    ];

    /**
     * @return list<UpstreamFixture>
     */
    public static function all(): array
    {
        return [...self::apod(), ...self::onThisDay(), ...self::openMeteo()];
    }

    /**
     * @return list<UpstreamFixture>
     */
    public static function repeatable(): array
    {
        return array_values(array_filter(self::all(), static fn (UpstreamFixture $fixture): bool => $fixture->isRepeatable()));
    }

    /**
     * @return list<UpstreamFixture>
     */
    public static function requestedByOurClients(): array
    {
        return array_values(array_filter(self::all(), static fn (UpstreamFixture $fixture): bool => $fixture->call instanceof ClientCall));
    }

    /**
     * @return list<UpstreamFixture>
     */
    public static function requestedTogetherWith(ClientCall $call): array
    {
        return array_values(array_filter(self::requestedByOurClients(), static fn (UpstreamFixture $fixture): bool => $call->isMadeWith($fixture->call ?? $call)));
    }

    /**
     * @return list<UpstreamFixture>
     */
    private static function apod(): array
    {
        return [
            new UpstreamFixture('apod/placeholder-2026-11-18.json', self::APOD.'2026-11-18', Response::HTTP_OK, Trim::None, Recording::Frozen, Comparison::Exact, new ClientCall(SourceName::Apod, '2026-11-18', self::APOD.'2026-11-18')),
            self::apodPassthrough('apod/passthrough-2024-03-15.json', '2024-03-15'),
            self::apodPassthrough('apod/passthrough-video-2021-06-23.json', '2021-06-23'),
            self::apodPassthrough('apod/passthrough-first-picture-1995-06-16.json', '1995-06-16'),
            new UpstreamFixture('apod/date-out-of-range.json', self::APOD.'1995-06-15', Response::HTTP_BAD_REQUEST, Trim::None, Recording::Frozen, Comparison::Exact, null),
        ];
    }

    private static function apodPassthrough(string $path, string $day): UpstreamFixture
    {
        $date = new \DateTimeImmutable($day);

        return new UpstreamFixture($path, self::APOD_PASSTHROUGH.$date->format('ymd'), Response::HTTP_OK, Trim::ApodPassthrough, Recording::Repeatable, Comparison::Exact, new ClientCall(SourceName::Apod, $day, self::APOD.$day));
    }

    /**
     * @return list<UpstreamFixture>
     */
    private static function onThisDay(): array
    {
        return array_map(self::onThisDayList(...), ['selected', 'births', 'deaths']);
    }

    private static function onThisDayList(string $list): UpstreamFixture
    {
        $url = self::ON_THIS_DAY.$list.'/03/15';

        return new UpstreamFixture("on_this_day/{$list}-03-15.json", $url, Response::HTTP_OK, Trim::OnThisDayReadFields, Recording::Repeatable, Comparison::Shape, new ClientCall(SourceName::OnThisDay, self::ON_THIS_DAY_DATE, $url));
    }

    /**
     * @return list<UpstreamFixture>
     */
    private static function openMeteo(): array
    {
        return [
            self::archive('open_meteo/archive-2024-03-15.json', 48.86, 2.35, '2024-03-15'),
            self::archive('open_meteo/archive-summer-2024-07-01.json', 48.86, 2.35, '2024-07-01'),
            self::archive('open_meteo/archive-sunset-after-midnight-2024-05-17.json', 69.65, 18.96, '2024-05-17'),
            self::archive('open_meteo/archive-polar-day-2024-06-21.json', 78.22, 15.65, '2024-06-21'),
            self::archive('open_meteo/archive-polar-night-2024-12-21.json', 78.22, 15.65, '2024-12-21'),
            self::archive('open_meteo/archive-point-nemo-2024-03-15.json', -48.88, -123.39, '2024-03-15'),
            self::archive('open_meteo/archive-north-pole-2024-03-15.json', 90.0, 0.0, '2024-03-15'),
            new UpstreamFixture('open_meteo/forecast-2026-11-20.json', self::weatherUrl(self::FORECAST, 48.86, 2.35, '2026-11-20'), Response::HTTP_OK, Trim::None, Recording::Frozen, Comparison::Exact, new ClientCall(SourceName::Weather, '2026-11-20', self::weatherUrl(self::FORECAST, 48.86, 2.35, '2026-11-20'), 48.86, 2.35)),
            new UpstreamFixture('open_meteo/forecast-beyond-the-horizon-2026-12-05.json', self::weatherUrl(self::FORECAST, 21.30, -157.85, '2026-12-05'), Response::HTTP_OK, Trim::None, Recording::Frozen, Comparison::Exact, null),
            new UpstreamFixture('open_meteo/forecast-out-of-range.json', self::weatherUrl(self::FORECAST, 48.86, 2.35, '2026-12-06'), Response::HTTP_BAD_REQUEST, Trim::None, Recording::Frozen, Comparison::Exact, null),
        ];
    }

    private static function archive(string $path, float $latitude, float $longitude, string $day): UpstreamFixture
    {
        $url = self::weatherUrl(self::ARCHIVE, $latitude, $longitude, $day);

        return new UpstreamFixture($path, $url, Response::HTTP_OK, Trim::None, Recording::Repeatable, Comparison::Exact, new ClientCall(SourceName::Weather, $day, $url, $latitude, $longitude));
    }

    private static function weatherUrl(string $endpoint, float $latitude, float $longitude, string $day): string
    {
        $query = ['latitude' => \sprintf(self::COORDINATE, $latitude), 'longitude' => \sprintf(self::COORDINATE, $longitude), 'start_date' => $day, 'end_date' => $day, ...self::WEATHER_QUERY];

        return $endpoint.http_build_query($query);
    }
}
