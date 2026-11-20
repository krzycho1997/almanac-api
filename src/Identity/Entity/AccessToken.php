<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use App\Identity\Exception\InvalidTokenLifetimeException;
use App\Identity\IssuedToken;
use App\Identity\Repository\AccessTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccessTokenRepository::class)]
#[ORM\Table(name: 'identity_access_token')]
#[ORM\UniqueConstraint(name: 'uniq_identity_access_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_identity_access_token_user', columns: ['user_id'])]
class AccessToken
{
    private const string HASH_ALGORITHM = 'sha256';
    private const int HASH_LENGTH = 64;
    private const int RANDOM_BYTES = 32;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) User $user;

    #[ORM\Column(length: self::HASH_LENGTH, options: ['fixed' => true])]
    public private(set) string $tokenHash;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public private(set) \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    private function __construct(User $user, string $tokenHash, \DateTimeImmutable $expiresAt, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
    }

    public static function issue(User $user, \DateInterval $ttl, \DateTimeImmutable $now): IssuedToken
    {
        $issuedAt = self::toWholeSeconds($now);
        $expiresAt = $issuedAt->add($ttl);

        if ($expiresAt > $issuedAt) {
            return self::issueUntil($user, $expiresAt, $issuedAt);
        }

        throw InvalidTokenLifetimeException::notPositive();
    }

    public static function hash(#[\SensitiveParameter] string $plainToken): string
    {
        return hash(self::HASH_ALGORITHM, $plainToken);
    }

    private static function issueUntil(User $user, \DateTimeImmutable $expiresAt, \DateTimeImmutable $issuedAt): IssuedToken
    {
        $plainToken = bin2hex(random_bytes(self::RANDOM_BYTES));

        return new IssuedToken(new self($user, self::hash($plainToken), $expiresAt, $issuedAt), $plainToken);
    }

    private static function toWholeSeconds(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimestamp($moment->getTimestamp());
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
