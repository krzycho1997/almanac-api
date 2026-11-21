<?php

declare(strict_types=1);

namespace App\Bookmark;

final readonly class BookmarkPage
{
    /**
     * @param list<BookmarkEntry> $entries
     */
    public function __construct(
        public array $entries,
        public int $total,
        public PageRequest $request,
    ) {
    }

    public function totalPages(): int
    {
        return intdiv($this->total + $this->request->limit - 1, $this->request->limit);
    }
}
