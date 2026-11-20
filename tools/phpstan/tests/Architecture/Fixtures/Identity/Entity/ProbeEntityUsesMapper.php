<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use App\Identity\Mapper\ProbeMapper;

class ProbeEntityUsesMapper
{
    public function __construct(
        public ProbeMapper $mapper,
    ) {
    }
}
