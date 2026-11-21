<?php

declare(strict_types=1);

namespace App\Tests\Support\Bookmark;

use App\Briefing\Contract\DiscoveryCatalog;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryView;

final class InMemoryDiscoveryCatalog implements DiscoveryCatalog
{
    /**
     * @var array<non-empty-string, DiscoveryView>
     */
    private array $views = [];

    public function __construct(
        private readonly ?DiscoveryCatalog $fallback = null,
    ) {
    }

    public function add(DiscoveryView ...$views): void
    {
        foreach ($views as $view) {
            $this->views[$view->id->toRfc4122()] = $view;
        }
    }

    public function find(DiscoveryId $id): ?DiscoveryView
    {
        return $this->findMany($id)[$id->toRfc4122()] ?? null;
    }

    public function findMany(DiscoveryId ...$ids): array
    {
        $known = array_intersect_key($this->views, self::keyed($ids));

        return [...$known, ...$this->fromFallback(array_diff_key(self::keyed($ids), $known))];
    }

    /**
     * @param array<non-empty-string, DiscoveryId> $ids
     *
     * @return array<non-empty-string, DiscoveryView>
     */
    private function fromFallback(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->fallback?->findMany(...array_values($ids)) ?? [];
    }

    /**
     * @param array<DiscoveryId> $ids
     *
     * @return array<non-empty-string, DiscoveryId>
     */
    private static function keyed(array $ids): array
    {
        return array_combine(array_map(static fn (DiscoveryId $id): string => $id->toRfc4122(), $ids), $ids);
    }
}
