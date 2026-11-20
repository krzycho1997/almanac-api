<?php

declare(strict_types=1);

namespace App\Briefing\Model;

final readonly class ProbeReadonlyModel
{
    public function __construct(
        public string $title,
    ) {
    }
}
