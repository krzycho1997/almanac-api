<?php

declare(strict_types=1);

namespace App\Tests\Identity\Functional;

use App\Identity\AccessTokenIssuer;
use App\Identity\Email;
use App\Identity\IssuedToken;
use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Shared\RequestId\RequestIdListener;
use App\Tests\Support\Controller\AuthenticatedUserProbeController;
use App\Tests\Support\FunctionalTestCase;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AccessTokenAuthenticationTest extends FunctionalTestCase
{
    private const string AUTHORIZATION = 'HTTP_AUTHORIZATION';
    private const string WWW_AUTHENTICATE = 'WWW-Authenticate';
    private const string CONTENT_TYPE = 'Content-Type';
    private const string MISSING_TOKEN_CHALLENGE = 'Bearer';
    private const string INVALID_TOKEN_CHALLENGE = 'Bearer error="invalid_token"';
    private const string ADDRESS = 'ada@example.com';
    private const string THIRTY_DAYS = 'P30D';
    private const string HEALTH = '/api/health';
    private const string ONE_HOUR = 'PT1H';

    public function testLetsAValidBearerTokenThroughAsItsOwner(): void
    {
        // Given
        $issued = self::issue(self::ADDRESS, self::THIRTY_DAYS);

        // When
        $this->requestProbe(self::bearer($issued->plainToken));

        // Then
        self::assertProbeSaw($issued);
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('requestsWithoutAToken')]
    public function testChallengesARequestWithoutAToken(array $server): void
    {
        // When
        $this->client->request(Request::METHOD_GET, AuthenticatedUserProbeController::PATH, server: $server);

        // Then
        self::assertUnauthorized(self::MISSING_TOKEN_CHALLENGE);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function requestsWithoutAToken(): iterable
    {
        yield 'no Authorization header' => [[]];
        yield 'another scheme' => [[self::AUTHORIZATION => 'Basic YWRhOnNlY3JldA==']];
        yield 'Bearer without a value' => [[self::AUTHORIZATION => 'Bearer ']];
        yield 'Bearer with two values' => [[self::AUTHORIZATION => 'Bearer one two']];
        yield 'lower-case scheme' => [[self::AUTHORIZATION => 'bearer '.str_repeat('0', 64)]];
    }

    public function testRefusesAnUnknownToken(): void
    {
        // When
        $this->requestProbe(self::bearer(str_repeat('0', 64)));

        // Then
        self::assertUnauthorized(self::INVALID_TOKEN_CHALLENGE);
    }

    public function testRefusesATokenOnceItExpires(): void
    {
        // Given
        $issued = self::issue(self::ADDRESS, self::ONE_HOUR);
        self::mockTime($issued->token->expiresAt);

        // When
        $this->requestProbe(self::bearer($issued->plainToken));

        // Then
        self::assertUnauthorized(self::INVALID_TOKEN_CHALLENGE);
    }

    public function testRefusesTheOldTokenAndAcceptsTheNewOneAfterRotation(): void
    {
        // Given
        $old = self::issue(self::ADDRESS, self::THIRTY_DAYS);
        $new = self::issue(self::ADDRESS, self::THIRTY_DAYS);

        // When / Then
        $this->requestProbe(self::bearer($old->plainToken));
        self::assertUnauthorized(self::INVALID_TOKEN_CHALLENGE);

        $this->requestProbe(self::bearer($new->plainToken));
        self::assertProbeSaw($new);
    }

    public function testRequiresATokenEvenWhereTheControllerWouldAcceptNobody(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, AuthenticatedUserProbeController::OPTIONAL_PATH);

        // Then
        self::assertUnauthorized(self::MISSING_TOKEN_CHALLENGE);
    }

    public function testLeavesRoutesOutsideBookmarksAlone(): void
    {
        // When
        $this->requestHealth(self::bearer(str_repeat('0', 64)));

        // Then
        self::assertResponseIsSuccessful();
    }

    public function testLogsNoWarningForARefusedToken(): void
    {
        // When
        $this->requestProbe(self::bearer(str_repeat('0', 64)));
        $this->client->request(Request::METHOD_GET, AuthenticatedUserProbeController::PATH);

        // Then
        self::assertSame([], self::levelsFrom(Level::Warning));
        self::assertSame([], self::loggedExceptionLevels());
    }

    private function requestHealth(string $authorization): void
    {
        $this->client->request(Request::METHOD_GET, self::HEALTH, server: [self::AUTHORIZATION => $authorization]);
    }

    private function requestProbe(string $authorization): void
    {
        $this->client->request(Request::METHOD_GET, AuthenticatedUserProbeController::PATH, server: [self::AUTHORIZATION => $authorization]);
    }

    private static function assertProbeSaw(IssuedToken $issued): void
    {
        self::assertResponseStatusCodeSame(AuthenticatedUserProbeController::STATUS);
        self::assertResponseHeaderSame(AuthenticatedUserProbeController::HEADER, $issued->token->user->id->toRfc4122());
    }

    private static function assertUnauthorized(string $challenge): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame(self::WWW_AUTHENTICATE, $challenge);
        self::assertResponseHeaderSame(self::CONTENT_TYPE, ProblemJsonErrorRenderer::PROBLEM_JSON);
        self::assertResponseNotHasHeader(AuthenticatedUserProbeController::HEADER);
        self::assertResponseHasHeader(RequestIdListener::HEADER);
    }

    private static function bearer(string $token): string
    {
        return 'Bearer '.$token;
    }

    private static function issue(string $email, string $ttl): IssuedToken
    {
        return self::getContainer()->get(AccessTokenIssuer::class)->issueFor(Email::fromString($email), new \DateInterval($ttl));
    }
}
