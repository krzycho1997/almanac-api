<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Unit;

use App\Bookmark\BookmarkPage;
use App\Bookmark\PageRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookmarkPageTest extends TestCase
{
    #[DataProvider('totals')]
    public function testCountsThePagesTheTotalFills(int $total, int $limit, int $pages): void
    {
        // Given
        $page = new BookmarkPage([], $total, new PageRequest(1, $limit));

        // When
        $totalPages = $page->totalPages();

        // Then
        self::assertSame($pages, $totalPages);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function totals(): iterable
    {
        yield 'no bookmarks' => [0, 20, 0];
        yield 'one bookmark' => [1, 20, 1];
        yield 'exactly full pages' => [40, 20, 2];
        yield 'a partial last page' => [41, 20, 3];
        yield 'one per page' => [3, 1, 3];
    }
}
