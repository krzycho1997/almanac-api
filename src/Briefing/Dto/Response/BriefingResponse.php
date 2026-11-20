<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class BriefingResponse
{
    /**
     * @param list<SourceKey> $missing
     */
    public function __construct(
        public string $date,
        public LocationDto $location,
        public SourcesDto $sources,
        public array $missing,
        public ?ApodDto $apod,
    ) {
    }
}
