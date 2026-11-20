<?php

declare(strict_types=1);

namespace App\Briefing\Model;

final readonly class PageRef
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $thumbnail,
        public ?string $wikidataId = null,
    ) {
    }
}
