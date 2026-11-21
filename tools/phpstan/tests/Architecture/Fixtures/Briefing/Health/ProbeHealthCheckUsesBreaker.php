<?php

declare(strict_types=1);

namespace App\Briefing\Health;

use App\Briefing\CircuitBreaker\CircuitBreaker;
use App\Briefing\Mapper\SourceKeyMapper;

final class ProbeHealthCheckUsesBreaker
{
    public function run(CircuitBreaker $breaker, SourceKeyMapper $mapper): void
    {
    }
}
