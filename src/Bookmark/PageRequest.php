<?php

declare(strict_types=1);

namespace App\Bookmark;

use App\Bookmark\Exception\InvalidPageRequestException;

final readonly class PageRequest
{
    public const int FIRST_PAGE = 1;
    public const int LAST_PAGE = 1_000_000;
    public const int DEFAULT_LIMIT = 20;
    public const int SMALLEST_LIMIT = 1;
    public const int LARGEST_LIMIT = 100;

    public function __construct(
        public int $page = self::FIRST_PAGE,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
        if (self::comesBeforeTheFirst($page)) {
            throw InvalidPageRequestException::pageBeforeTheFirst($page);
        }

        if (self::comesAfterTheLast($page)) {
            throw InvalidPageRequestException::pageAfterTheLast($page, self::LAST_PAGE);
        }

        if (self::isOutsideTheLimits($limit)) {
            throw InvalidPageRequestException::limitOutOfRange($limit, self::SMALLEST_LIMIT, self::LARGEST_LIMIT);
        }
    }

    public function offset(): int
    {
        return ($this->page - self::FIRST_PAGE) * $this->limit;
    }

    private static function comesBeforeTheFirst(int $page): bool
    {
        return $page < self::FIRST_PAGE;
    }

    private static function comesAfterTheLast(int $page): bool
    {
        return $page > self::LAST_PAGE;
    }

    private static function isOutsideTheLimits(int $limit): bool
    {
        return $limit < self::SMALLEST_LIMIT || $limit > self::LARGEST_LIMIT;
    }
}
