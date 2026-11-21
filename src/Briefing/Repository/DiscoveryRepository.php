<?php

declare(strict_types=1);

namespace App\Briefing\Repository;

use App\Briefing\BriefingDay;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Discovery\DiscoveryRecord;
use App\Briefing\Entity\Discovery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Discovery>
 */
final class DiscoveryRepository extends ServiceEntityRepository
{
    private const string UPSERT = <<<'SQL'
        INSERT INTO briefing_discovery (id, key, type, date, title, data, created_at)
        SELECT record.id, record.key, record.type, record.date, record.title, record.data, :createdAt
        FROM jsonb_to_recordset(:records) AS record (id uuid, key text, type text, date date, title text, data jsonb)
        ORDER BY record.key
        ON CONFLICT DO NOTHING
        SQL;

    private const array UPSERT_TYPES = ['records' => Types::JSONB, 'createdAt' => Types::DATETIMETZ_IMMUTABLE];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Discovery::class);
    }

    /**
     * @param non-empty-list<DiscoveryId> $ids
     *
     * @return list<Discovery>
     */
    public function findByIds(array $ids): array
    {
        return $this->findBy(['id' => $ids]);
    }

    /**
     * @param list<DiscoveryRecord> $records
     */
    public function upsertDiscoveries(array $records, \DateTimeImmutable $createdAt): int
    {
        if ([] === $records) {
            return 0;
        }

        return (int) $this->connection()->executeStatement(self::UPSERT, ['records' => self::rows($records), 'createdAt' => $createdAt], self::UPSERT_TYPES);
    }

    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    /**
     * @param list<DiscoveryRecord> $records
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(array $records): array
    {
        return array_map(static fn (DiscoveryRecord $record): array => [
            'id' => $record->id->toRfc4122(),
            'key' => $record->key,
            'type' => $record->type->value,
            'date' => $record->date?->format(BriefingDay::FORMAT),
            'title' => $record->title,
            'data' => [Discovery::SUMMARY => $record->summary, Discovery::URL => $record->url],
        ], $records);
    }
}
