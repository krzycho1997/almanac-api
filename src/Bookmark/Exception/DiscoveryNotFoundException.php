<?php

declare(strict_types=1);

namespace App\Bookmark\Exception;

use App\Briefing\Contract\DiscoveryId;

final class DiscoveryNotFoundException extends \RuntimeException
{
    public static function withId(DiscoveryId $id): self
    {
        return new self("No discovery has the id {$id->toRfc4122()}.");
    }
}
