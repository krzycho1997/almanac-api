<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class EventDto
{
    /**
     * @param list<PageDto> $pages
     */
    public function __construct(
        public int $year,
        public string $text,
        public array $pages,
    ) {
    }
}
