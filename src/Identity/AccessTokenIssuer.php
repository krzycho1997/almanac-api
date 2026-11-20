<?php

declare(strict_types=1);

namespace App\Identity;

use App\Identity\Entity\AccessToken;
use App\Identity\Entity\User;
use App\Identity\Repository\AccessTokenRepository;
use App\Identity\Repository\UserRepository;
use App\Shared\Logging\LogContextKey;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

#[WithMonologChannel(self::CHANNEL)]
final readonly class AccessTokenIssuer
{
    private const string CHANNEL = 'identity';
    private const string USER_CREATED = 'User {userId} created';
    private const string TOKENS_ROTATED = 'Access tokens rotated for user {userId}';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private AccessTokenRepository $tokens,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function issueFor(Email $email, \DateInterval $ttl): IssuedToken
    {
        $issuance = $this->entityManager->wrapInTransaction(fn (): Issuance => $this->issueInTransaction($email, $ttl));
        $this->log($issuance);

        return $issuance->issued;
    }

    private function issueInTransaction(Email $email, \DateInterval $ttl): Issuance
    {
        $now = $this->clock->now();
        $this->users->lockAddress($email);
        $user = $this->users->findOneByEmail($email);

        if ($user instanceof User) {
            return $this->rotate($user, $ttl, $now);
        }

        return $this->create($email, $ttl, $now);
    }

    private function create(Email $email, \DateInterval $ttl, \DateTimeImmutable $now): Issuance
    {
        $user = new User($email, $now);
        $this->users->add($user);

        return Issuance::forNewUser($this->issue($user, $ttl, $now));
    }

    private function rotate(User $user, \DateInterval $ttl, \DateTimeImmutable $now): Issuance
    {
        $revokedCount = $this->tokens->deleteAllForUser($user);

        return Issuance::afterRotation($this->issue($user, $ttl, $now), $revokedCount);
    }

    private function issue(User $user, \DateInterval $ttl, \DateTimeImmutable $now): IssuedToken
    {
        $issued = AccessToken::issue($user, $ttl, $now);
        $this->tokens->add($issued->token);

        return $issued;
    }

    private function log(Issuance $issuance): void
    {
        $context = self::contextOf($issuance->issued->token);

        if (null === $issuance->revokedCount) {
            $this->logger->notice(self::USER_CREATED, $context);

            return;
        }

        $this->logger->notice(self::TOKENS_ROTATED, [LogContextKey::RevokedCount->value => $issuance->revokedCount] + $context);
    }

    /**
     * @return array<string, mixed>
     */
    private static function contextOf(AccessToken $token): array
    {
        return [
            LogContextKey::UserId->value => $token->user->id->toRfc4122(),
            LogContextKey::ExpiresAt->value => $token->expiresAt,
        ];
    }
}
