<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class BriefingResponse
{
    /**
     * @param list<SourceKey> $missing
     * @param list<EventDto>  $events
     * @param list<PersonDto> $births
     * @param list<PersonDto> $deaths
     */
    public function __construct(
        public string $date,
        public LocationDto $location,
        public SourcesDto $sources,
        public array $missing,
        public ?ApodDto $apod,
        public array $events,
        public array $births,
        public array $deaths,
    ) {
    }
}
