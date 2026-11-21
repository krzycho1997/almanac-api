<?php

declare(strict_types=1);

namespace App\Briefing\Source\OpenMeteo;

use App\Briefing\BriefingDay;
use App\Briefing\Location;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\WeatherKind;
use App\Briefing\Source\PendingFetch;
use App\Briefing\Source\SourceClient;
use App\Briefing\Source\UpstreamResponseDecoder;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class OpenMeteoClient implements SourceClient
{
    private const string FORECAST_PATH = 'v1/forecast';
    private const string ARCHIVE_PATH = 'v1/archive';
    private const string COORDINATE = '%.2F';
    private const string QUERY = 'query';
    private const array FIXED_QUERY = [
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,sunrise,sunset,daylight_duration',
        'timezone' => 'auto',
        'timeformat' => 'unixtime',
        'temperature_unit' => 'celsius',
        'wind_speed_unit' => 'kmh',
        'precipitation_unit' => 'mm',
    ];

    public function __construct(
        #[Target('open_meteo.client')]
        private HttpClientInterface $forecastHttpClient,
        #[Target('open_meteo_archive.client')]
        private HttpClientInterface $archiveHttpClient,
        private UpstreamResponseDecoder $decoder,
        private OpenMeteoMapper $mapper,
        private WeatherHorizon $horizon,
        private ClockInterface $clock,
    ) {
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        return $this->horizon->covers($date);
    }

    #[\NoDiscard]
    public function request(\DateTimeImmutable $date, Location $location): PendingFetch
    {
        $day = $date->format(BriefingDay::FORMAT);
        $kind = $this->horizon->kindFor($date);
        [$client, $path] = $this->endpointFor($kind);
        $response = $client->request(Request::METHOD_GET, $path, [self::QUERY => self::query($day, $location)]);

        return new PendingFetch([$response], $this->weatherFrom($response, $kind, $day), $this->clock);
    }

    /**
     * @return array{HttpClientInterface, string}
     */
    private function endpointFor(WeatherKind $kind): array
    {
        return match ($kind) {
            WeatherKind::Final => [$this->archiveHttpClient, self::ARCHIVE_PATH],
            WeatherKind::Provisional, WeatherKind::Forecast => [$this->forecastHttpClient, self::FORECAST_PATH],
        };
    }

    /**
     * @return array<string, string>
     */
    private static function query(string $day, Location $location): array
    {
        return [
            'latitude' => \sprintf(self::COORDINATE, $location->latitude),
            'longitude' => \sprintf(self::COORDINATE, $location->longitude),
            'start_date' => $day,
            'end_date' => $day,
            ...self::FIXED_QUERY,
        ];
    }

    /**
     * @return \Closure(): DailyWeather
     */
    private function weatherFrom(ResponseInterface $response, WeatherKind $kind, string $day): \Closure
    {
        return fn (): DailyWeather => $this->mapper->map($this->decoder->decode($response, OpenMeteoApiResponse::class), $kind, $day);
    }
}
