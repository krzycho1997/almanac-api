<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

use App\Briefing\Model\MediaType;

final readonly class ProbeResponseUsesModel
{
    public function __construct(
        public MediaType $mediaType,
    ) {
    }
}
