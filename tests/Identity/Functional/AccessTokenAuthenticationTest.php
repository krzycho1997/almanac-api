<?php

declare(strict_types=1);

namespace App\Tests\Identity\Functional;

use App\Identity\AccessTokenIssuer;
use App\Identity\Email;
use App\Identity\IssuedToken;
use App\Tests\Support\Controller\AuthenticatedUserProbeController;
use App\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AccessTokenAuthenticationTest extends FunctionalTestCase
{
    private const string AUTHORIZATION = 'HTTP_AUTHORIZATION';
    private const string THIRTY_DAYS = 'P30D';

    public function testLetsAValidBearerTokenThroughAsItsOwner(): void
    {
        // Given
        $issued = self::issue('ada@example.com');

        // When
        $this->client->request(Request::METHOD_GET, AuthenticatedUserProbeController::PATH, server: [self::AUTHORIZATION => 'Bearer '.$issued->plainToken]);

        // Then
        self::assertResponseStatusCodeSame(AuthenticatedUserProbeController::STATUS);
        self::assertResponseHeaderSame(AuthenticatedUserProbeController::HEADER, $issued->token->user->id->toRfc4122());
    }

    public function testRefusesARequestWithoutAToken(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, AuthenticatedUserProbeController::PATH);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private static function issue(string $email): IssuedToken
    {
        return self::getContainer()->get(AccessTokenIssuer::class)->issueFor(Email::fromString($email), new \DateInterval(self::THIRTY_DAYS));
    }
}
