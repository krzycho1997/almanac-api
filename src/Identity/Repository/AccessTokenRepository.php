<?php

declare(strict_types=1);

namespace App\Identity\Repository;

use App\Identity\Contract\UserIdType;
use App\Identity\Entity\AccessToken;
use App\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessToken>
 */
final class AccessTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessToken::class);
    }

    public function findOneByHash(string $tokenHash): ?AccessToken
    {
        $token = $this->getEntityManager()
            ->createQuery('SELECT token, owner FROM '.AccessToken::class.' token JOIN token.user owner WHERE token.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getOneOrNullResult();

        if ($token instanceof AccessToken) {
            return $token;
        }

        return null;
    }

    public function add(AccessToken $token): void
    {
        $this->getEntityManager()->persist($token);
    }

    public function deleteAllForUser(User $user): int
    {
        return $this->getEntityManager()
            ->createQuery('DELETE FROM '.AccessToken::class.' token WHERE token.user = :user')
            ->setParameter('user', $user->id, UserIdType::NAME)
            ->execute();
    }
}
