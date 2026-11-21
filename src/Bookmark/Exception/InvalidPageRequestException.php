<?php

declare(strict_types=1);

namespace App\Bookmark\Exception;

final class InvalidPageRequestException extends \InvalidArgumentException
{
    public static function pageBeforeTheFirst(int $page): self
    {
        return new self("Page {$page} comes before the first page.");
    }

    public static function pageAfterTheLast(int $page, int $last): self
    {
        return new self("Page {$page} comes after the last page a request may ask for, {$last}.");
    }

    public static function limitOutOfRange(int $limit, int $smallest, int $largest): self
    {
        return new self("A page holds from {$smallest} to {$largest} bookmarks, not {$limit}.");
    }
}
