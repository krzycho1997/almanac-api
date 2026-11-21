<?php

declare(strict_types=1);

namespace App\Bookmark;

use App\Briefing\Contract\DiscoveryId;

final class ProbeParsesDiscoveryId
{
    public function run(string $id): DiscoveryId
    {
        return DiscoveryId::fromString($id);
    }
}
