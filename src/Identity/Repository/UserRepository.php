<?php

declare(strict_types=1);

namespace App\Identity\Repository;

use App\Identity\Doctrine\EmailType;
use App\Identity\Email;
use App\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    private const string LOCK_SPACE = 'identity_user.email';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function lockAddress(Email $email): void
    {
        $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtext(:space), hashtext(:email))',
            ['space' => self::LOCK_SPACE, 'email' => $email],
            ['email' => EmailType::NAME],
        );
    }

    public function findOneByEmail(Email $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function add(User $user): void
    {
        $this->getEntityManager()->persist($user);
    }
}
