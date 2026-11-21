<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Functional;

use App\Bookmark\BookmarkId;
use App\Bookmark\BookmarkService;
use App\Bookmark\PageRequest;
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
use Symfony\Component\Uid\UuidV4;

final class DeleteBookmarkTest extends FunctionalTestCase
{
    use InteractsWithTestServices;
    use IssuesAccessTokens;

    private const string BOOKMARKS = '/api/bookmarks/';
    private const string PICTURE = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    private const string ADA = 'ada@example.com';
    private const string GRACE = 'grace@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        self::testService(InMemoryDiscoveryCatalog::class)->add(new DiscoveryView(DiscoveryId::fromString(self::PICTURE), DiscoveryType::Apod, new \DateTimeImmutable('2024-03-15'), 'Portrait of NGC 1055', null, null));
    }

    public function testRemovesTheUsersBookmark(): void
    {
        // Given
        [$ada, $authorization] = self::signedIn(self::ADA);
        $id = self::bookmark($ada);

        // When
        $this->client->request(Request::METHOD_DELETE, self::BOOKMARKS.$id, server: $authorization);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertSame(0, self::bookmarksOf($ada));
    }

    public function testAnswersNotFoundForSomeoneElsesBookmarkAndKeepsIt(): void
    {
        // Given
        [$ada] = self::signedIn(self::ADA);
        [, $grace] = self::signedIn(self::GRACE);
        $id = self::bookmark($ada);

        // When
        $this->client->request(Request::METHOD_DELETE, self::BOOKMARKS.$id, server: $grace);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(1, self::bookmarksOf($ada));
    }

    #[DataProvider('idsOfNoBookmark')]
    public function testAnswersNotFoundForAnIdOfNoBookmark(string $id): void
    {
        // Given
        [, $authorization] = self::signedIn(self::ADA);

        // When
        $this->client->request(Request::METHOD_DELETE, self::BOOKMARKS.$id, server: $authorization);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsOfNoBookmark(): iterable
    {
        yield 'unknown version 7' => [new BookmarkId()->toRfc4122()];
        yield 'version 4' => [new UuidV4()->toRfc4122()];
        yield 'base58 of a version 7' => [new BookmarkId()->toBase58()];
        yield 'not a uuid' => ['not-a-uuid'];
    }

    #[DataProvider('otherFormsOfAnExistingId')]
    public function testAnswersNotFoundForAnotherFormOfAnExistingIdAndKeepsTheBookmark(\Closure $form): void
    {
        // Given
        [$ada, $authorization] = self::signedIn(self::ADA);
        $id = BookmarkId::fromString(self::bookmark($ada));

        // When
        $this->client->request(Request::METHOD_DELETE, self::BOOKMARKS.$form($id), server: $authorization);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(1, self::bookmarksOf($ada));
    }

    /**
     * @return iterable<string, array{\Closure(BookmarkId): string}>
     */
    public static function otherFormsOfAnExistingId(): iterable
    {
        yield 'base58' => [static fn (BookmarkId $id): string => $id->toBase58()];
        yield 'upper case' => [static fn (BookmarkId $id): string => strtoupper($id->toRfc4122())];
    }

    public function testChallengesARequestWithoutAToken(): void
    {
        // Given
        [$ada] = self::signedIn(self::ADA);
        $id = self::bookmark($ada);

        // When
        $this->client->request(Request::METHOD_DELETE, self::BOOKMARKS.$id);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(1, self::bookmarksOf($ada));
    }

    private static function bookmark(UserId $owner): string
    {
        return self::service()->add($owner, DiscoveryId::fromString(self::PICTURE))->bookmark->id->toRfc4122();
    }

    private static function bookmarksOf(UserId $owner): int
    {
        return self::service()->page($owner, new PageRequest())->total;
    }

    private static function service(): BookmarkService
    {
        return self::testService(BookmarkService::class);
    }
}
