<?php

declare(strict_types=1);

namespace App\Briefing\Source;

use App\Briefing\SourceName;

final readonly class SourceFetch
{
    public function __construct(
        public SourceName $source,
        public PendingFetch $pending,
    ) {
    }
}
