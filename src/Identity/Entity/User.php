<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use App\Identity\Contract\UserId;
use App\Identity\Contract\UserIdType;
use App\Identity\Doctrine\EmailType;
use App\Identity\Email;
use App\Identity\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'identity_user')]
#[ORM\UniqueConstraint(name: 'uniq_identity_user_email', columns: ['email'])]
class User implements UserInterface
{
    private const string ROLE = 'ROLE_USER';

    #[ORM\Id]
    #[ORM\Column(type: UserIdType::NAME)]
    public private(set) UserId $id;

    #[ORM\Column(type: EmailType::NAME, length: Email::MAX_LENGTH)]
    public private(set) Email $email;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(Email $email, \DateTimeImmutable $createdAt)
    {
        $this->id = new UserId();
        $this->email = $email;
        $this->createdAt = $createdAt->setTimestamp($createdAt->getTimestamp());
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [self::ROLE];
    }
}
