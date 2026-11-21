<?php

declare(strict_types=1);

namespace App\Bookmark\Dto\Response;

final readonly class PaginationMeta
{
    public function __construct(
        public int $page,
        public int $limit,
        public int $total,
        public int $totalPages,
    ) {
    }
}
