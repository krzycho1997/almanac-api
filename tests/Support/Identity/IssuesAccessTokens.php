<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\AccessTokenIssuer;
use App\Identity\Contract\UserId;
use App\Identity\Email;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-require-extends KernelTestCase
 */
trait IssuesAccessTokens
{
    private const string BEARER = 'Bearer ';
    private const string AUTHORIZATION_HEADER = 'HTTP_AUTHORIZATION';
    private const string TOKEN_LIFETIME = 'P30D';

    /**
     * @return array{UserId, array<string, string>}
     */
    protected static function signedIn(string $email): array
    {
        $issued = self::getContainer()->get(AccessTokenIssuer::class)->issueFor(Email::fromString($email), new \DateInterval(self::TOKEN_LIFETIME));

        return [$issued->token->user->id, [self::AUTHORIZATION_HEADER => self::BEARER.$issued->plainToken]];
    }
}
