<?php

declare(strict_types=1);

namespace App\Identity\Contract;

interface AuthenticatedUser
{
    public UserId $id { get; }
}
