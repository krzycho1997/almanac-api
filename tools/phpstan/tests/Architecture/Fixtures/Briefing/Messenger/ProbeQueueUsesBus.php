<?php

declare(strict_types=1);

namespace App\Briefing\Messenger;

use Symfony\Component\Messenger\MessageBusInterface;

final class ProbeQueueUsesBus
{
    public function enqueue(MessageBusInterface $bus): void
    {
    }
}
