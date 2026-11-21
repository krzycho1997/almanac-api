<?php

declare(strict_types=1);

namespace App\Bookmark\Exception;

use App\Bookmark\BookmarkId;

final class BookmarkNotFoundException extends \RuntimeException
{
    public static function withId(BookmarkId $id): self
    {
        return new self("No bookmark of this user has the id {$id->toRfc4122()}.");
    }
}
