<?php

declare(strict_types=1);

namespace App\Bookmark\Dto\Response;

final readonly class BookmarkListResponse
{
    /**
     * @param list<BookmarkResponse> $data
     */
    public function __construct(
        public array $data,
        public PaginationMeta $meta,
    ) {
    }
}
