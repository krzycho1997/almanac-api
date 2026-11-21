<?php

declare(strict_types=1);

namespace App\Bookmark;

use App\Briefing\Contract\DiscoveryId;

final class ProbeMintsDiscoveryId
{
    public function run(): DiscoveryId
    {
        return new DiscoveryId('2ed6657d-e927-568b-95e1-2665a8aea6a2');
    }
}
