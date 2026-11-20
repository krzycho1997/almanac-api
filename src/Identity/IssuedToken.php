<?php

declare(strict_types=1);

namespace App\Identity;

use App\Identity\Entity\AccessToken;

final readonly class IssuedToken
{
    public function __construct(
        public AccessToken $token,
        #[\SensitiveParameter]
        public string $plainToken,
    ) {
    }
}
