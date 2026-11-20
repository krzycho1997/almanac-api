<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class ProbeResponseWithItsOwnEnum
{
    public function __construct(
        public SourceStatusValue $status,
    ) {
    }
}
