<?php

declare(strict_types=1);

namespace App\Briefing\Source\Weather;

use App\Briefing\BriefingDay;
use App\Briefing\Model\WeatherKind;
use Psr\Clock\ClockInterface;

final readonly class WeatherHorizon
{
    private const string UTC = 'UTC';
    private const string FIRST_RECORDED_DAY = '1940-01-01';
    private const string LAST_FORECAST_DAY = '+14 days';
    private const string FIRST_PROVISIONAL_DAY = '-5 days';
    private const string TODAY = 'today';

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        $day = $date->format(BriefingDay::FORMAT);

        if ($day < self::FIRST_RECORDED_DAY) {
            return false;
        }

        return $day <= $this->dayFromToday(self::LAST_FORECAST_DAY);
    }

    public function kindFor(\DateTimeImmutable $date): WeatherKind
    {
        $day = $date->format(BriefingDay::FORMAT);

        if ($day < $this->dayFromToday(self::FIRST_PROVISIONAL_DAY)) {
            return WeatherKind::Final;
        }

        if ($day < $this->dayFromToday(self::TODAY)) {
            return WeatherKind::Provisional;
        }

        return WeatherKind::Forecast;
    }

    private function dayFromToday(string $offset): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::UTC))->modify($offset)->format(BriefingDay::FORMAT);
    }
}
