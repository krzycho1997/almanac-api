<?php

declare(strict_types=1);

namespace App\Bookmark;

use App\Bookmark\Entity\Bookmark;
use App\Briefing\Contract\DiscoveryView;

final readonly class BookmarkEntry
{
    public function __construct(
        public Bookmark $bookmark,
        public DiscoveryView $discovery,
    ) {
    }
}
