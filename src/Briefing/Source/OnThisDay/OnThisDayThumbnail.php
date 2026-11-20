<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

final readonly class OnThisDayThumbnail
{
    public function __construct(
        public ?string $source = null,
    ) {
    }
}
