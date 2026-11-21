<?php

declare(strict_types=1);

namespace App\Bookmark\Contract;

use App\Shared\Contract\UidIdType;
use Symfony\Component\Uid\UuidV7;

/**
 * @extends UidIdType<UuidV7>
 */
final class ProbeBookmarkIdType extends UidIdType
{
    public function getName(): string
    {
        return 'probe_bookmark_id';
    }

    protected function getUidClass(): string
    {
        return UuidV7::class;
    }
}
