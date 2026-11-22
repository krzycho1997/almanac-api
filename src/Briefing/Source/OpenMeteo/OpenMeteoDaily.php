<?php

declare(strict_types=1);

namespace App\Briefing\Source\OpenMeteo;

use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class OpenMeteoDaily
{
    /**
     * @param list<int>        $time
     * @param list<int|null>   $weatherCode
     * @param list<float|null> $temperatureMax
     * @param list<float|null> $temperatureMin
     * @param list<float|null> $precipitationSum
     * @param list<float|null> $windSpeedMax
     * @param list<int|null>   $sunrise
     * @param list<int|null>   $sunset
     * @param list<float|null> $daylightDuration
     */
    public function __construct(
        public array $time = [],
        #[SerializedName('weather_code')]
        public array $weatherCode = [],
        #[SerializedName('temperature_2m_max')]
        public array $temperatureMax = [],
        #[SerializedName('temperature_2m_min')]
        public array $temperatureMin = [],
        #[SerializedName('precipitation_sum')]
        public array $precipitationSum = [],
        #[SerializedName('wind_speed_10m_max')]
        public array $windSpeedMax = [],
        public array $sunrise = [],
        public array $sunset = [],
        #[SerializedName('daylight_duration')]
        public array $daylightDuration = [],
    ) {
    }
}
