<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Briefing\Source\Source;

final class ProbeUsesSourceContract
{
    public function run(Source $source): void
    {
    }
}
