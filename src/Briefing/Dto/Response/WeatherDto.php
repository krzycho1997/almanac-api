<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class WeatherDto
{
    public function __construct(
        public WeatherKindValue $kind,
        public string $timezone,
        public ?float $temperatureMin,
        public ?float $temperatureMax,
        public ?float $precipitationSum,
        public ?float $windSpeedMax,
        public ?int $weatherCode,
        public ?string $description,
        public ?string $sunrise,
        public ?string $sunset,
        public ?int $daylightDuration,
    ) {
    }
}
