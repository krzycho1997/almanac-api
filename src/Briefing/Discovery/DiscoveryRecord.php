<?php

declare(strict_types=1);

namespace App\Briefing\Discovery;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;

final readonly class DiscoveryRecord
{
    public function __construct(
        public DiscoveryId $id,
        public string $key,
        public DiscoveryType $type,
        public ?\DateTimeImmutable $date,
        public string $title,
        public ?string $summary,
        public ?string $url,
    ) {
    }
}
