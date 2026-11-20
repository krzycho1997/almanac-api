<?php

declare(strict_types=1);

namespace App\Shared\ErrorHandler;

use Doctrine\ORM\EntityManagerInterface;

final class ProbeListenerUsesDoctrine
{
    public function run(EntityManagerInterface $entityManager): void
    {
    }
}
