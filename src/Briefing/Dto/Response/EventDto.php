<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

use App\Briefing\Contract\DiscoveryId;

final readonly class EventDto
{
    /**
     * @param list<PageDto> $pages
     */
    public function __construct(
        public DiscoveryId $discoveryId,
        public int $year,
        public string $text,
        public array $pages,
    ) {
    }
}
