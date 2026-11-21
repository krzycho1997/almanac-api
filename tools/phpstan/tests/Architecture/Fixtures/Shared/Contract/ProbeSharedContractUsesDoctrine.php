<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use Doctrine\DBAL\Connection;

final class ProbeSharedContractUsesDoctrine
{
    public function run(Connection $connection): void
    {
    }
}
