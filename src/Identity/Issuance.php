<?php

declare(strict_types=1);

namespace App\Identity;

final readonly class Issuance
{
    private function __construct(
        public IssuedToken $issued,
        public ?int $revokedCount,
    ) {
    }

    public static function forNewUser(IssuedToken $issued): self
    {
        return new self($issued, null);
    }

    public static function afterRotation(IssuedToken $issued, int $revokedCount): self
    {
        return new self($issued, $revokedCount);
    }
}
