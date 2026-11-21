<?php

declare(strict_types=1);

namespace App\Briefing\Contract;

use App\Shared\Contract\UidIdType;

/**
 * @extends UidIdType<DiscoveryId>
 */
final class DiscoveryIdType extends UidIdType
{
    public const string NAME = 'discovery_id';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getUidClass(): string
    {
        return DiscoveryId::class;
    }
}
