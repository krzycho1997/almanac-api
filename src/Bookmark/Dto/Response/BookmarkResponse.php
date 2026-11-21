<?php

declare(strict_types=1);

namespace App\Bookmark\Dto\Response;

final readonly class BookmarkResponse
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public DiscoveryDto $discovery,
    ) {
    }
}
