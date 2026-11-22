<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

use App\Briefing\Location;
use App\Briefing\SnapshotScope;
use App\Briefing\Source\Source;
use App\Briefing\Source\SourceClient;
use App\Briefing\SourceName;

final readonly class OnThisDaySource implements Source
{
    public const SourceName NAME = SourceName::OnThisDay;
    private const string MONTH_AND_DAY = 'm-d';

    public function __construct(
        private OnThisDayClient $client,
    ) {
    }

    public function name(): SourceName
    {
        return self::NAME;
    }

    public function refreshAfter(): ?\DateInterval
    {
        return null;
    }

    public function scopeFor(\DateTimeImmutable $date, Location $location): SnapshotScope
    {
        return SnapshotScope::undated(self::NAME, $date->format(self::MONTH_AND_DAY));
    }

    public function client(): SourceClient
    {
        return $this->client;
    }
}
