<?php

declare(strict_types=1);

namespace App\Briefing\Messenger;

use Doctrine\ORM\EntityManagerInterface;

final class ProbeQueueUsesDoctrine
{
    public function enqueue(EntityManagerInterface $entityManager): void
    {
    }
}
