<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class PageDto
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $thumbnail,
    ) {
    }
}
