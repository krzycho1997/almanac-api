<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Identity\Entity\AccessToken;
use App\Identity\Entity\User;
use App\Identity\Repository\AccessTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

final readonly class AccessTokenHandler implements AccessTokenHandlerInterface
{
    private const string INVALID_TOKEN = 'Invalid access token.';

    public function __construct(
        private AccessTokenRepository $tokens,
        private ClockInterface $clock,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->activeToken($accessToken);

        if ($token instanceof AccessToken) {
            return self::badgeFor($token->user);
        }

        throw new BadCredentialsException(self::INVALID_TOKEN);
    }

    private function activeToken(#[\SensitiveParameter] string $accessToken): ?AccessToken
    {
        if (AccessToken::couldHaveBeenIssued($accessToken)) {
            return $this->unexpired($this->tokens->findOneByHash(AccessToken::hash($accessToken)));
        }

        return null;
    }

    private function unexpired(?AccessToken $token): ?AccessToken
    {
        if (true === $token?->isExpired($this->clock->now())) {
            return null;
        }

        return $token;
    }

    private static function badgeFor(User $user): UserBadge
    {
        return new UserBadge($user->getUserIdentifier(), static fn (): User => $user);
    }
}
