<?php

declare(strict_types=1);

namespace App\Briefing\Contract;

final readonly class DiscoveryView
{
    public function __construct(
        public DiscoveryId $id,
        public DiscoveryType $type,
        public ?\DateTimeImmutable $date,
        public string $title,
        public ?string $summary,
        public ?string $url,
    ) {
    }
}
