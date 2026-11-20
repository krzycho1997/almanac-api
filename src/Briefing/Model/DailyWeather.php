<?php

declare(strict_types=1);

namespace App\Briefing\Model;

final readonly class DailyWeather implements SourcePayload
{
    public function __construct(
        public WeatherKind $kind,
        public string $timezone,
        public ?float $temperatureMin,
        public ?float $temperatureMax,
        public ?float $precipitationSum,
        public ?float $windSpeedMax,
        public ?int $weatherCode,
        public ?string $sunrise,
        public ?string $sunset,
        public ?int $daylightDuration,
        public Attribution $attribution,
    ) {
    }
}
