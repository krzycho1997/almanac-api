<?php

declare(strict_types=1);

namespace App\Bookmark\Dto\Response;

final readonly class DiscoveryDto
{
    public function __construct(
        public string $id,
        public DiscoveryKind $type,
        public ?string $date,
        public string $title,
        public ?string $summary,
        public ?string $url,
    ) {
    }
}
