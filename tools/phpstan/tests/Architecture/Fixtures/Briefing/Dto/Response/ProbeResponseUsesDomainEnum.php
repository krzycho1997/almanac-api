<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

use App\Briefing\SourceStatus;

final readonly class ProbeResponseUsesDomainEnum
{
    public function __construct(
        public SourceStatus $status,
    ) {
    }
}
