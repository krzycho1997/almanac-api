<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

final readonly class OnThisDayLink
{
    public function __construct(
        public ?string $page = null,
    ) {
    }
}
