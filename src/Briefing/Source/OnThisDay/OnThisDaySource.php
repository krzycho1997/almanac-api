<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

use App\Briefing\Source\Source;
use App\Briefing\Source\SourceClient;
use App\Briefing\SourceName;

final readonly class OnThisDaySource implements Source
{
    public function __construct(
        private OnThisDayClient $client,
    ) {
    }

    public function name(): SourceName
    {
        return SourceName::OnThisDay;
    }

    public function client(): SourceClient
    {
        return $this->client;
    }
}
