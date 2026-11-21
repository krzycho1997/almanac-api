<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Functional;

use App\Bookmark\BookmarkService;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Contract\DiscoveryView;
use App\Identity\Contract\UserId;
use App\Tests\Support\Bookmark\InMemoryDiscoveryCatalog;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\Identity\IssuesAccessTokens;
use App\Tests\Support\InteractsWithTestServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ListBookmarksTest extends FunctionalTestCase
{
    use InteractsWithTestServices;
    use IssuesAccessTokens;

    private const string BOOKMARKS = '/api/bookmarks';
    private const string ADA = 'ada@example.com';
    private const string GRACE = 'grace@example.com';
    private const array DISCOVERIES = [
        'c05f9b6a-9262-5859-b45d-743a62e1bc6e' => 'Portrait of NGC 1055',
        '30ad2d96-39f2-514a-806e-58f2041d1ce6' => 'Julius Caesar',
        '1604e20a-544e-5ab3-97a4-e7861e6ea31a' => 'Germany occupies Bohemia.',
    ];
    private const string DATA = 'data';
    private const string META = 'meta';
    private const string TITLE = 'title';
    private const string DISCOVERY = 'discovery';
    private const string VIOLATIONS = 'violations';
    private const string PROPERTY_PATH = 'propertyPath';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::DISCOVERIES as $id => $title) {
            self::catalog()->add(new DiscoveryView(DiscoveryId::fromString($id), DiscoveryType::Event, null, $title, null, null));
        }
    }

    public function testListsOnlyTheUsersBookmarksNewestFirst(): void
    {
        // Given
        [$ada, $authorization] = self::signedIn(self::ADA);
        [$grace] = self::signedIn(self::GRACE);
        self::bookmarkAll($ada);
        self::bookmark($grace, array_key_first(self::DISCOVERIES));

        // When
        $this->client->request(Request::METHOD_GET, self::BOOKMARKS, server: $authorization);

        // Then
        self::assertResponseIsSuccessful();
        $list = $this->json();
        self::assertSame(array_reverse(array_values(self::DISCOVERIES)), self::titles($list));
        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 3, 'totalPages' => 1], $list[self::META]);
    }

    /**
     * @param list<string>       $titles
     * @param array<string, int> $meta
     */
    #[DataProvider('pages')]
    public function testPagesTheBookmarks(string $query, array $titles, array $meta): void
    {
        // Given
        [$ada, $authorization] = self::signedIn(self::ADA);
        self::bookmarkAll($ada);

        // When
        $this->client->request(Request::METHOD_GET, self::BOOKMARKS.'?'.$query, server: $authorization);

        // Then
        self::assertResponseIsSuccessful();
        $list = $this->json();
        self::assertSame($titles, self::titles($list));
        self::assertSame($meta, $list[self::META]);
    }

    /**
     * @return iterable<string, array{string, list<string>, array<string, int>}>
     */
    public static function pages(): iterable
    {
        yield 'first page' => ['page=1&limit=2', ['Germany occupies Bohemia.', 'Julius Caesar'], ['page' => 1, 'limit' => 2, 'total' => 3, 'totalPages' => 2]];
        yield 'last page' => ['page=2&limit=2', ['Portrait of NGC 1055'], ['page' => 2, 'limit' => 2, 'total' => 3, 'totalPages' => 2]];
        yield 'page after the last' => ['page=3&limit=2', [], ['page' => 3, 'limit' => 2, 'total' => 3, 'totalPages' => 2]];
        yield 'smallest limit' => ['limit=1', ['Germany occupies Bohemia.'], ['page' => 1, 'limit' => 1, 'total' => 3, 'totalPages' => 3]];
        yield 'largest limit' => ['limit=100', ['Germany occupies Bohemia.', 'Julius Caesar', 'Portrait of NGC 1055'], ['page' => 1, 'limit' => 100, 'total' => 3, 'totalPages' => 1]];
    }

    public function testListsNothingForAUserWithoutBookmarks(): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);

        // When
        $this->client->request(Request::METHOD_GET, self::BOOKMARKS, server: $authorization);

        // Then
        self::assertResponseIsSuccessful();
        self::assertSame([self::DATA => [], self::META => ['page' => 1, 'limit' => 20, 'total' => 0, 'totalPages' => 0]], $this->json());
    }

    #[DataProvider('readingMethods')]
    public function testChallengesARequestWithoutAToken(string $method): void
    {
        // When
        $this->client->request($method, self::BOOKMARKS);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readingMethods(): iterable
    {
        yield 'GET' => [Request::METHOD_GET];
        yield 'HEAD' => [Request::METHOD_HEAD];
    }

    #[DataProvider('invalidQueries')]
    public function testRefusesAnInvalidPageOrLimit(string $query, string $field): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);

        // When
        $this->client->request(Request::METHOD_GET, self::BOOKMARKS.'?'.$query, server: $authorization);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = $this->json();
        self::assertIsArray($problem[self::VIOLATIONS] ?? null);
        self::assertSame([$field], array_column($problem[self::VIOLATIONS], self::PROPERTY_PATH));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'page zero' => ['page=0', 'page'];
        yield 'negative page' => ['page=-1', 'page'];
        yield 'page not a number' => ['page=abc', 'page'];
        yield 'fractional page' => ['page=1.5', 'page'];
        yield 'page as a list' => ['page[]=1', 'page'];
        yield 'empty page' => ['page=', 'page'];
        yield 'page with a trailing newline' => ['page=1%0A', 'page'];
        yield 'page after the last' => ['page=1000001', 'page'];
        yield 'page beyond the integers' => ['page=99999999999999999999', 'page'];
        yield 'limit zero' => ['limit=0', 'limit'];
        yield 'limit over 100' => ['limit=101', 'limit'];
        yield 'limit with spaces' => ['limit=%2010', 'limit'];
    }

    private static function bookmarkAll(UserId $owner): void
    {
        foreach (array_keys(self::DISCOVERIES) as $id) {
            self::bookmark($owner, $id);
        }
    }

    private static function bookmark(UserId $owner, string $discoveryId): void
    {
        self::testService(BookmarkService::class)->add($owner, DiscoveryId::fromString($discoveryId));
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<mixed>
     */
    private static function titles(array $list): array
    {
        self::assertIsArray($list[self::DATA] ?? null);

        return array_values(array_map(self::titleOf(...), $list[self::DATA]));
    }

    private static function titleOf(mixed $bookmark): mixed
    {
        self::assertIsArray($bookmark);
        self::assertIsArray($bookmark[self::DISCOVERY] ?? null);

        return $bookmark[self::DISCOVERY][self::TITLE] ?? null;
    }

    private static function catalog(): InMemoryDiscoveryCatalog
    {
        return self::testService(InMemoryDiscoveryCatalog::class);
    }
}
