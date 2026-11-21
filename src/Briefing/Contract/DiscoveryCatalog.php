<?php

declare(strict_types=1);

namespace App\Briefing\Contract;

interface DiscoveryCatalog
{
    public function find(DiscoveryId $id): ?DiscoveryView;

    /**
     * @return array<non-empty-string, DiscoveryView>
     */
    public function findMany(DiscoveryId ...$ids): array;
}
