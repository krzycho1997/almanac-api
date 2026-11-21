<?php

declare(strict_types=1);

namespace App\Identity\Contract;

use App\Shared\Contract\UidIdType;

/**
 * @extends UidIdType<UserId>
 */
final class UserIdType extends UidIdType
{
    public const string NAME = 'user_id';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getUidClass(): string
    {
        return UserId::class;
    }
}
