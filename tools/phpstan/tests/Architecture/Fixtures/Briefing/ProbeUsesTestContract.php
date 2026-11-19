<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Tests\Contract\ProbeFake;

final class ProbeUsesTestContract
{
    public function run(ProbeFake $fake): void
    {
    }
}
