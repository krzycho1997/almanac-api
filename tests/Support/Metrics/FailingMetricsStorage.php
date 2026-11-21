<?php

declare(strict_types=1);

namespace App\Tests\Support\Metrics;

use Prometheus\Exception\StorageException;
use Prometheus\Storage\Adapter;

final class FailingMetricsStorage implements Adapter
{
    public int $writes = 0;

    public function __construct(
        private readonly \Throwable $failure = new StorageException('Redis is down'),
    ) {
    }

    public function collect(bool $sortMetrics = true): array
    {
        throw $this->failure;
    }

    public function updateSummary(array $data): void
    {
        $this->fail();
    }

    public function updateHistogram(array $data): void
    {
        $this->fail();
    }

    public function updateGauge(array $data): void
    {
        $this->fail();
    }

    public function updateCounter(array $data): void
    {
        $this->fail();
    }

    public function wipeStorage(): void
    {
    }

    private function fail(): void
    {
        ++$this->writes;

        throw $this->failure;
    }
}
