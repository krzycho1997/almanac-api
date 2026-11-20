<?php

declare(strict_types=1);

namespace App\Briefing\Source\Weather;

use App\Briefing\Source\Source;
use App\Briefing\Source\SourceClient;
use App\Briefing\SourceName;

final readonly class WeatherSource implements Source
{
    public const SourceName NAME = SourceName::Weather;

    public function __construct(
        private OpenMeteoClient $client,
    ) {
    }

    public function name(): SourceName
    {
        return self::NAME;
    }

    public function client(): SourceClient
    {
        return $this->client;
    }
}
