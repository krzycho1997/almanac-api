<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Discovery\DiscoveryExtractor;
use App\Briefing\Discovery\DiscoveryRecord;
use App\Briefing\Repository\DiscoveryRepository;
use App\Briefing\Repository\SourceSnapshotRepository;
use Doctrine\DBAL\Connection;

final readonly class SnapshotRefresher
{
    public function __construct(
        private Connection $connection,
        private SourceSnapshotRepository $snapshots,
        private DiscoveryRepository $discoveries,
        private DiscoveryExtractor $extractor,
    ) {
    }

    /**
     * @param list<SnapshotScope> $scopes
     *
     * @return array<value-of<SourceName>, StoredSnapshot>
     */
    public function storedFor(array $scopes): array
    {
        return $this->snapshots->findForScopes($scopes);
    }

    /**
     * @param list<SourceResult>                         $results
     * @param array<value-of<SourceName>, SnapshotScope> $scopes
     *
     * @return array<value-of<SourceName>, StoredSnapshot>
     */
    public function saveAndReread(array $results, array $scopes, \DateTimeImmutable $day, \DateTimeImmutable $now): array
    {
        $this->connection->transactional(function () use ($results, $scopes, $day, $now): void {
            $this->snapshots->saveResults($results, $scopes, $now);
            $this->discoveries->upsertDiscoveries($this->recordsOf($results, $day), $now);
        });

        return $this->snapshots->findForScopes(self::scopesOf($results, $scopes));
    }

    /**
     * @param list<SourceResult>                         $results
     * @param array<value-of<SourceName>, SnapshotScope> $scopes
     *
     * @return list<SnapshotScope>
     */
    private static function scopesOf(array $results, array $scopes): array
    {
        return array_values(array_intersect_key($scopes, array_flip(array_map(static fn (SourceResult $result): string => $result->source->value, $results))));
    }

    /**
     * @param list<SourceResult> $results
     *
     * @return list<DiscoveryRecord>
     */
    private function recordsOf(array $results, \DateTimeImmutable $day): array
    {
        $records = [];

        foreach ($results as $result) {
            if ($result->hasData()) {
                $records = [...$records, ...$this->extractor->extract($result->payload, $day)];
            }
        }

        return $records;
    }
}
