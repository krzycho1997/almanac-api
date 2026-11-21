<?php

declare(strict_types=1);

namespace App\Bookmark\Exception;

use App\Briefing\Contract\DiscoveryId;

final class AlreadyBookmarkedException extends \RuntimeException
{
    public static function forDiscovery(DiscoveryId $id): self
    {
        return new self("The discovery {$id->toRfc4122()} is already bookmarked.");
    }
}
