<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Unit;

use App\Bookmark\Exception\InvalidPageRequestException;
use App\Bookmark\PageRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageRequestTest extends TestCase
{
    public function testStartsAtTheFirstPageOfTwenty(): void
    {
        // When
        $request = new PageRequest();

        // Then
        self::assertSame([1, 20, 0], [$request->page, $request->limit, $request->offset()]);
    }

    #[DataProvider('pages')]
    public function testSkipsTheBookmarksOfTheEarlierPages(int $page, int $limit, int $offset): void
    {
        // When
        $request = new PageRequest($page, $limit);

        // Then
        self::assertSame($offset, $request->offset());
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function pages(): iterable
    {
        yield 'first page' => [1, 10, 0];
        yield 'third page' => [3, 10, 20];
        yield 'smallest limit' => [5, 1, 4];
        yield 'largest limit' => [2, 100, 100];
        yield 'last page' => [1_000_000, 100, 99_999_900];
    }

    #[DataProvider('invalidRequests')]
    public function testRefusesAPageOrLimitOutOfRange(int $page, int $limit): void
    {
        // Then / When
        $this->expectException(InvalidPageRequestException::class);
        new PageRequest($page, $limit);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'page zero' => [0, 20];
        yield 'negative page' => [-1, 20];
        yield 'page after the last' => [1_000_001, 20];
        yield 'largest integer page' => [\PHP_INT_MAX, 100];
        yield 'limit zero' => [1, 0];
        yield 'limit over 100' => [1, 101];
    }
}
