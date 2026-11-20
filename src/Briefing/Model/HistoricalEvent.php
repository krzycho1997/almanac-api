<?php

declare(strict_types=1);

namespace App\Briefing\Model;

final readonly class HistoricalEvent
{
    /**
     * @param list<PageRef> $pages
     */
    public function __construct(
        public int $year,
        public string $text,
        public array $pages,
    ) {
    }
}
