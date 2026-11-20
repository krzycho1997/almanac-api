<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

final readonly class OnThisDayApiResponse
{
    /**
     * @param list<OnThisDayEntry> $selected
     * @param list<OnThisDayEntry> $births
     * @param list<OnThisDayEntry> $deaths
     */
    public function __construct(
        public array $selected = [],
        public array $births = [],
        public array $deaths = [],
    ) {
    }
}
