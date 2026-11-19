<?php

declare(strict_types=1);

namespace App\Bookmark;

use App\Identity\Contract\ProbeUserId;
use App\Shared\ProbeValue;

final class ProbeUsesAllowed
{
    public function run(ProbeUserId $user, ProbeValue $value, \DateTimeImmutable $at, ProbeSibling $sibling): void
    {
    }
}
