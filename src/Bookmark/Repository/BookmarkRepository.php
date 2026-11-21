<?php

declare(strict_types=1);

namespace App\Bookmark\Repository;

use App\Bookmark\BookmarkId;
use App\Bookmark\Doctrine\BookmarkIdType;
use App\Bookmark\Entity\Bookmark;
use App\Bookmark\PageRequest;
use App\Briefing\Contract\DiscoveryIdType;
use App\Identity\Contract\UserId;
use App\Identity\Contract\UserIdType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bookmark>
 */
final class BookmarkRepository extends ServiceEntityRepository
{
    private const string INSERT_IF_ABSENT = <<<'SQL'
        INSERT INTO bookmark (id, user_id, discovery_id, created_at)
        VALUES (:id, :userId, :discoveryId, :createdAt)
        ON CONFLICT (user_id, discovery_id) DO NOTHING
        SQL;

    private const array INSERT_TYPES = [
        'id' => BookmarkIdType::NAME,
        'userId' => UserIdType::NAME,
        'discoveryId' => DiscoveryIdType::NAME,
        'createdAt' => Types::DATETIMETZ_IMMUTABLE,
    ];

    private const string OWNED = 'FROM '.Bookmark::class.' bookmark WHERE bookmark.userId = :owner';
    private const string OWNER = 'owner';
    private const int ONE_ROW = 1;
    private const string ID = 'id';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bookmark::class);
    }

    public function insertIfAbsent(Bookmark $bookmark): bool
    {
        return self::ONE_ROW === (int) $this->getEntityManager()->getConnection()->executeStatement(self::INSERT_IF_ABSENT, self::insertParameters($bookmark), self::INSERT_TYPES);
    }

    /**
     * @return list<Bookmark>
     */
    public function findPageForUser(UserId $owner, PageRequest $page): array
    {
        return $this->getEntityManager()
            ->createQuery('SELECT bookmark '.self::OWNED.' ORDER BY bookmark.createdAt DESC, bookmark.id DESC')
            ->setParameter(self::OWNER, $owner, UserIdType::NAME)
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit)
            ->getResult();
    }

    public function countForUser(UserId $owner): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(bookmark.id) '.self::OWNED)
            ->setParameter(self::OWNER, $owner, UserIdType::NAME)
            ->getSingleScalarResult();
    }

    public function deleteForUser(UserId $owner, BookmarkId $id): bool
    {
        return self::ONE_ROW === $this->getEntityManager()
            ->createQuery('DELETE FROM '.Bookmark::class.' bookmark WHERE bookmark.id = :id AND bookmark.userId = :owner')
            ->setParameter(self::ID, $id, BookmarkIdType::NAME)
            ->setParameter(self::OWNER, $owner, UserIdType::NAME)
            ->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private static function insertParameters(Bookmark $bookmark): array
    {
        return [
            'id' => $bookmark->id,
            'userId' => $bookmark->userId,
            'discoveryId' => $bookmark->discoveryId,
            'createdAt' => $bookmark->createdAt,
        ];
    }
}
