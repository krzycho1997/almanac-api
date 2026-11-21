<?php

declare(strict_types=1);

namespace App\Briefing\Health;

use Doctrine\ORM\EntityManagerInterface;

final class ProbeHealthCheckUsesDoctrine
{
    public function run(EntityManagerInterface $entityManager): void
    {
    }
}
