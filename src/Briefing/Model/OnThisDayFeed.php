<?php

declare(strict_types=1);

namespace App\Briefing\Model;

final readonly class OnThisDayFeed implements SourcePayload
{
    /**
     * @param list<HistoricalEvent> $events
     * @param list<NotablePerson>   $births
     * @param list<NotablePerson>   $deaths
     */
    public function __construct(
        public array $events,
        public array $births,
        public array $deaths,
    ) {
    }
}
