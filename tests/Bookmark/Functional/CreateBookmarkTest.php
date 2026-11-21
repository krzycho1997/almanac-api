<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Functional;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Contract\DiscoveryView;
use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Tests\Support\Bookmark\InMemoryDiscoveryCatalog;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\Identity\IssuesAccessTokens;
use App\Tests\Support\InteractsWithTestServices;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class CreateBookmarkTest extends FunctionalTestCase
{
    use InteractsWithTestServices;
    use IssuesAccessTokens;

    private const string BOOKMARKS = '/api/bookmarks';
    private const string PICTURE = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    private const string UNKNOWN = '2ed6657d-e927-568b-95e1-2665a8aea6a2';
    private const string CAESAR = '30ad2d96-39f2-514a-806e-58f2041d1ce6';
    private const string ADA = 'ada@example.com';
    private const string JSON = 'application/json';
    private const string CONTENT_TYPE = 'CONTENT_TYPE';
    private const string CONTENT_TYPE_HEADER = 'Content-Type';
    private const string DISCOVERY_ID = 'discoveryId';
    private const string USER_ID = 'userId';
    private const string VIOLATIONS = 'violations';
    private const string PROPERTY_PATH = 'propertyPath';

    protected function setUp(): void
    {
        parent::setUp();
        self::catalog()->add(new DiscoveryView(DiscoveryId::fromString(self::PICTURE), DiscoveryType::Apod, new \DateTimeImmutable('2024-03-15'), 'Portrait of NGC 1055', 'A galaxy.', 'https://apod.nasa.gov/a.jpg'));
    }

    public function testBookmarksADiscoveryAndAnswersWithIt(): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);

        // When
        $this->post([self::DISCOVERY_ID => self::PICTURE], $authorization);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $bookmark = self::json((string) $this->client->getResponse()->getContent());
        self::assertIsString($bookmark['id'] ?? null);
        self::assertTrue(Uuid::isValid($bookmark['id']));
        self::assertSame('2026-11-18T12:00:00Z', $bookmark['createdAt'] ?? null);
        self::assertSame(
            ['id' => self::PICTURE, 'type' => 'apod', 'date' => '2024-03-15', 'title' => 'Portrait of NGC 1055', 'summary' => 'A galaxy.', 'url' => 'https://apod.nasa.gov/a.jpg'],
            $bookmark['discovery'] ?? null,
        );
    }

    public function testChallengesARequestWithoutAToken(): void
    {
        // When
        $this->post([self::DISCOVERY_ID => self::PICTURE], []);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnswersNotFoundForADiscoveryNoBriefingProduced(): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);

        // When
        $this->post([self::DISCOVERY_ID => self::UNKNOWN], $authorization);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame(self::CONTENT_TYPE_HEADER, ProblemJsonErrorRenderer::PROBLEM_JSON);
    }

    public function testAnswersConflictForASecondBookmarkOfTheSameDiscoveryAndLogsItAtNotice(): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);
        $this->post([self::DISCOVERY_ID => self::PICTURE], $authorization);

        // When
        $this->post([self::DISCOVERY_ID => self::PICTURE], $authorization);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame([Level::Notice], self::loggedExceptionLevels());
        self::assertSame([], self::levelsFrom(Level::Warning));
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testRefusesADiscoveryIdThatIsNotAVersion5Uuid(array $payload): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);

        // When
        $this->post($payload, $authorization);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertViolationOfTheDiscoveryId();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [[self::DISCOVERY_ID => null]];
        yield 'number' => [[self::DISCOVERY_ID => 42]];
        yield 'list' => [[self::DISCOVERY_ID => [self::PICTURE]]];
        yield 'not a uuid' => [[self::DISCOVERY_ID => 'not-a-uuid']];
        yield 'version 4' => [[self::DISCOVERY_ID => '9b2e1c4a-3f6d-4a8b-9c1e-2d7f5a6b8c90']];
        yield 'version 7' => [[self::DISCOVERY_ID => '019ad6a4-2c3e-7b1f-8a2d-4e5f6a7b8c9d']];
        yield 'base58 form of a version 5' => [[self::DISCOVERY_ID => Uuid::fromString(self::PICTURE)->toBase58()]];
    }

    public function testRefusesAnEmptyBodyWithAViolation(): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);

        // When
        $this->client->request(Request::METHOD_POST, self::BOOKMARKS, server: [self::CONTENT_TYPE => self::JSON] + $authorization, content: '');

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertViolationOfTheDiscoveryId();
    }

    public function testRefusesAFieldTheBodyDoesNotDefineWithAViolation(): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);

        // When
        $this->post([self::DISCOVERY_ID => self::PICTURE, self::USER_ID => 'someone-else'], $authorization);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = self::json((string) $this->client->getResponse()->getContent());
        self::assertIsArray($problem[self::VIOLATIONS] ?? null);
        self::assertSame([self::USER_ID], array_column($problem[self::VIOLATIONS], self::PROPERTY_PATH));
    }

    public function testRefusesMalformedJson(): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);

        // When
        $this->client->request(Request::METHOD_POST, self::BOOKMARKS, server: [self::CONTENT_TYPE => self::JSON] + $authorization, content: '{"discoveryId":');

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[DataProvider('bodiesThatAreNotJson')]
    public function testRefusesABodyThatIsNotJson(string $contentType, string $content): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);

        // When
        $this->client->request(Request::METHOD_POST, self::BOOKMARKS, server: [self::CONTENT_TYPE => $contentType] + $authorization, content: $content);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiesThatAreNotJson(): iterable
    {
        yield 'plain text' => ['text/plain', self::PICTURE];
        yield 'form' => ['application/x-www-form-urlencoded', self::DISCOVERY_ID.'='.self::PICTURE];
        yield 'XML' => ['application/xml', '<bookmark><discoveryId>'.self::PICTURE.'</discoveryId></bookmark>'];
    }

    public function testAnswersWithTheEmptyFieldsOfAPerson(): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);
        self::catalog()->add(new DiscoveryView(DiscoveryId::fromString(self::CAESAR), DiscoveryType::Person, null, 'Julius Caesar', null, null));

        // When
        $this->post([self::DISCOVERY_ID => self::CAESAR], $authorization);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $bookmark = self::json((string) $this->client->getResponse()->getContent());
        self::assertSame(['id' => self::CAESAR, 'type' => 'person', 'date' => null, 'title' => 'Julius Caesar', 'summary' => null, 'url' => null], $bookmark['discovery'] ?? null);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $authorization
     */
    private function post(array $payload, array $authorization): void
    {
        $this->client->request(Request::METHOD_POST, self::BOOKMARKS, server: [self::CONTENT_TYPE => self::JSON] + $authorization, content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function assertViolationOfTheDiscoveryId(): void
    {
        $problem = self::json((string) $this->client->getResponse()->getContent());
        self::assertIsArray($problem[self::VIOLATIONS] ?? null);
        self::assertSame([self::DISCOVERY_ID], array_column($problem[self::VIOLATIONS], self::PROPERTY_PATH));
    }

    private static function catalog(): InMemoryDiscoveryCatalog
    {
        return self::testService(InMemoryDiscoveryCatalog::class);
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $content): array
    {
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
