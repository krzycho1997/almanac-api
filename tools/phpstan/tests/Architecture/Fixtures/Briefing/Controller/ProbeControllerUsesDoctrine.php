<?php

declare(strict_types=1);

namespace App\Briefing\Controller;

use Doctrine\ORM\EntityManagerInterface;

final class ProbeControllerUsesDoctrine
{
    public function run(EntityManagerInterface $entityManager): void
    {
    }
}
