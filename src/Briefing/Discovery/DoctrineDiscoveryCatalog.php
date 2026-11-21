<?php

declare(strict_types=1);

namespace App\Briefing\Discovery;

use App\Briefing\Contract\DiscoveryCatalog;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryView;
use App\Briefing\Entity\Discovery;
use App\Briefing\Repository\DiscoveryRepository;

final readonly class DoctrineDiscoveryCatalog implements DiscoveryCatalog
{
    public function __construct(
        private DiscoveryRepository $discoveries,
    ) {
    }

    public function find(DiscoveryId $id): ?DiscoveryView
    {
        return $this->findMany($id)[$id->toRfc4122()] ?? null;
    }

    public function findMany(DiscoveryId ...$ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return self::viewsById($this->discoveries->findByIds(array_values($ids)));
    }

    /**
     * @param list<Discovery> $discoveries
     *
     * @return array<non-empty-string, DiscoveryView>
     */
    private static function viewsById(array $discoveries): array
    {
        $views = [];

        foreach ($discoveries as $discovery) {
            $views[$discovery->id->toRfc4122()] = self::view($discovery);
        }

        return $views;
    }

    private static function view(Discovery $discovery): DiscoveryView
    {
        return new DiscoveryView($discovery->id, $discovery->type, $discovery->date, $discovery->title, self::text($discovery->data, Discovery::SUMMARY), self::text($discovery->data, Discovery::URL));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;

        if (\is_string($value)) {
            return $value;
        }

        return null;
    }
}
