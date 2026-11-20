<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

final readonly class OnThisDayContentUrls
{
    public function __construct(
        public ?OnThisDayLink $desktop = null,
    ) {
    }
}
