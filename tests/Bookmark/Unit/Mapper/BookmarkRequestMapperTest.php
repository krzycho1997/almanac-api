<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Unit\Mapper;

use App\Bookmark\Dto\Request\BookmarkListQuery;
use App\Bookmark\Dto\Request\CreateBookmarkRequest;
use App\Bookmark\Mapper\BookmarkRequestMapper;
use App\Bookmark\PageRequest;
use App\Briefing\Contract\DiscoveryId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookmarkRequestMapperTest extends TestCase
{
    private const string DISCOVERY = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';

    public function testConvertsTheRequestToItsDiscoveryId(): void
    {
        // When
        $id = new BookmarkRequestMapper()->convertToDiscoveryId(new CreateBookmarkRequest(self::DISCOVERY));

        // Then
        self::assertTrue(DiscoveryId::fromString(self::DISCOVERY)->equals($id));
    }

    public function testRefusesARequestThatSkippedValidation(): void
    {
        // Then / When
        $this->expectException(\LogicException::class);

        new BookmarkRequestMapper()->convertToDiscoveryId(new CreateBookmarkRequest());
    }

    #[DataProvider('queries')]
    public function testConvertsTheQueryToAPageRequest(BookmarkListQuery $query, PageRequest $expected): void
    {
        // When
        $request = new BookmarkRequestMapper()->convertToPageRequest($query);

        // Then
        self::assertEquals($expected, $request);
    }

    /**
     * @return iterable<string, array{BookmarkListQuery, PageRequest}>
     */
    public static function queries(): iterable
    {
        yield 'defaults' => [new BookmarkListQuery(), new PageRequest(PageRequest::FIRST_PAGE, PageRequest::DEFAULT_LIMIT)];
        yield 'page and limit' => [new BookmarkListQuery('3', '50'), new PageRequest(3, 50)];
        yield 'page only' => [new BookmarkListQuery('2'), new PageRequest(2, PageRequest::DEFAULT_LIMIT)];
    }
}
