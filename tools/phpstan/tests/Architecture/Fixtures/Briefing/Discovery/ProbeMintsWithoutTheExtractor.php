<?php

declare(strict_types=1);

namespace App\Briefing\Discovery;

use App\Briefing\Contract\DiscoveryId;
use Symfony\Component\Uid\Uuid;

final class ProbeMintsWithoutTheExtractor
{
    public function run(string $key): DiscoveryId
    {
        return new DiscoveryId(Uuid::v5(Uuid::fromString('179fdc61-e6b5-43fa-aa77-b824790a27fe'), $key)->toRfc4122());
    }
}
