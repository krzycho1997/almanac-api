<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

use App\Briefing\Contract\DiscoveryId;

final readonly class ApodDto
{
    public function __construct(
        public DiscoveryId $discoveryId,
        public string $title,
        public string $explanation,
        public ApodMediaType $mediaType,
        public ?string $url,
        public ?string $hdUrl,
        public ?string $credit,
    ) {
    }
}
