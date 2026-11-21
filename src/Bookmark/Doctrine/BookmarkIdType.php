<?php

declare(strict_types=1);

namespace App\Bookmark\Doctrine;

use App\Bookmark\BookmarkId;
use App\Shared\Contract\UidIdType;

/**
 * @extends UidIdType<BookmarkId>
 */
final class BookmarkIdType extends UidIdType
{
    public const string NAME = 'bookmark_id';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getUidClass(): string
    {
        return BookmarkId::class;
    }
}
