<?php

declare(strict_types=1);

namespace App\Tests\Support\Metrics;

use App\Shared\Metrics\Metric;
use App\Shared\Metrics\Metrics;

final class RecordingMetrics implements Metrics
{
    /**
     * @var list<array{Metric, array<string, string>}>
     */
    public array $increments = [];

    /**
     * @var list<array{Metric, float, array<string, string>}>
     */
    public array $observations = [];

    public function increment(Metric $metric, array $labels): void
    {
        $this->increments[] = [$metric, $labels];
    }

    public function observe(Metric $metric, float $seconds, array $labels): void
    {
        $this->observations[] = [$metric, $seconds, $labels];
    }
}
