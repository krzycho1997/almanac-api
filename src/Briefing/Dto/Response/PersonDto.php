<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

use App\Briefing\Contract\DiscoveryId;

final readonly class PersonDto
{
    public function __construct(
        public DiscoveryId $discoveryId,
        public int $year,
        public string $name,
        public ?string $description,
        public ?string $url,
        public ?string $thumbnail,
    ) {
    }
}
