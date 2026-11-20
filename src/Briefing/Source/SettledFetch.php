<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use App\Briefing\SourceResult;

final readonly class SettledFetch
{
    public function __construct(
        public SourceResult $result,
        public int $durationMs,
    ) {
    }
}
