<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

interface Metrics
{
    /**
     * @param array<string, string> $labels
     */
    public function increment(Metric $metric, array $labels): void;

    /**
     * @param array<string, string> $labels
     */
    public function observe(Metric $metric, float $seconds, array $labels): void;
}
