<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Shared\Metrics\PrometheusMetrics;

final class ProbeUsesMetricsAdapter
{
    public function run(PrometheusMetrics $metrics): void
    {
    }
}
