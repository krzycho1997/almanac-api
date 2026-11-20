<?php

declare(strict_types=1);

namespace App\Briefing\Source\Apod;

use App\Briefing\Source\Source;
use App\Briefing\Source\SourceClient;
use App\Briefing\SourceName;

final readonly class ApodSource implements Source
{
    public function __construct(
        private ApodClient $client,
    ) {
    }

    public function name(): SourceName
    {
        return SourceName::Apod;
    }

    public function client(): SourceClient
    {
        return $this->client;
    }
}
