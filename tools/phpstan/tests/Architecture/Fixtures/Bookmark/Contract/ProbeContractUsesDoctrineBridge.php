<?php

declare(strict_types=1);

namespace App\Bookmark\Contract;

use Symfony\Bridge\Doctrine\Types\UuidType;

final class ProbeContractUsesDoctrineBridge
{
    public function run(UuidType $type): void
    {
    }
}
