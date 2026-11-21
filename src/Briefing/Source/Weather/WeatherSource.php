<?php

declare(strict_types=1);

namespace App\Briefing\Source\Weather;

use App\Briefing\BriefingDay;
use App\Briefing\Location;
use App\Briefing\SnapshotScope;
use App\Briefing\Source\Source;
use App\Briefing\Source\SourceClient;
use App\Briefing\SourceName;

final readonly class WeatherSource implements Source
{
    public const SourceName NAME = SourceName::Weather;
    private const string REFRESH_AFTER = 'PT1H';
    private const string KEY = '%s@%s';

    public function __construct(
        private OpenMeteoClient $client,
    ) {
    }

    public function name(): SourceName
    {
        return self::NAME;
    }

    public function refreshAfter(): \DateInterval
    {
        return new \DateInterval(self::REFRESH_AFTER);
    }

    public function scopeFor(\DateTimeImmutable $date, Location $location): SnapshotScope
    {
        return SnapshotScope::dated(self::NAME, \sprintf(self::KEY, $date->format(BriefingDay::FORMAT), $location->key()), $date);
    }

    public function client(): SourceClient
    {
        return $this->client;
    }
}
