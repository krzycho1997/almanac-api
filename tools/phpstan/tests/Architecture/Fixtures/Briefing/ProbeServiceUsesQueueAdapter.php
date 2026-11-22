<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Messenger\ProbeQueueUsesBus;

final class ProbeServiceUsesQueueAdapter
{
    public function run(ProbeQueueUsesBus $queue): void
    {
    }
}
