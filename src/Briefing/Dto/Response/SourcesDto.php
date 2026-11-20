<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class SourcesDto
{
    public function __construct(
        public SourceStatusDto $apod,
        public SourceStatusDto $onThisDay,
        public SourceStatusDto $weather,
    ) {
    }
}
