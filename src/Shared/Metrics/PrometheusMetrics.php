<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

use App\Shared\Logging\LogContextKey;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class PrometheusMetrics implements Metrics
{
    private const string NAMESPACE = 'almanac';
    private const string UNAVAILABLE = 'Metrics store unavailable, dropping metrics for a while';
    private const string REST_AFTER_FAILURE = 'PT30S';

    private readonly CollectorRegistry $registry;
    private ?\DateTimeImmutable $unavailableUntil = null;

    public function __construct(
        Adapter $storage,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->registry = new CollectorRegistry($storage, false);
    }

    public function increment(Metric $metric, array $labels): void
    {
        $values = self::valuesOf($metric, $labels);

        $this->attempt($metric, fn () => $this->registry->getOrRegisterCounter(self::NAMESPACE, $metric->value, $metric->help(), $metric->labelNames())->inc($values));
    }

    public function observe(Metric $metric, float $seconds, array $labels): void
    {
        $values = self::valuesOf($metric, $labels);

        $this->attempt($metric, fn () => $this->registry->getOrRegisterHistogram(self::NAMESPACE, $metric->value, $metric->help(), $metric->labelNames(), $metric->buckets())->observe($seconds, $values));
    }

    /**
     * @param array<string, string> $labels
     */
    public function gauge(Metric $metric, float $value, array $labels): void
    {
        $values = self::valuesOf($metric, $labels);

        $this->attempt($metric, fn () => $this->registry->getOrRegisterGauge(self::NAMESPACE, $metric->value, $metric->help(), $metric->labelNames())->set($value, $values));
    }

    public function render(): string
    {
        return new RenderTextFormat()->render($this->registry->getMetricFamilySamples());
    }

    /**
     * @param \Closure(): void $write
     */
    private function attempt(Metric $metric, \Closure $write): void
    {
        if ($this->isResting()) {
            return;
        }

        try {
            $write();
        } catch (\Throwable $exception) {
            $this->rest($metric, $exception);
        }
    }

    private function isResting(): bool
    {
        if (null === $this->unavailableUntil) {
            return false;
        }

        return $this->clock->now() < $this->unavailableUntil;
    }

    private function rest(Metric $metric, \Throwable $exception): void
    {
        $this->unavailableUntil = $this->clock->now()->add(new \DateInterval(self::REST_AFTER_FAILURE));
        $this->logger->warning(self::UNAVAILABLE, [LogContextKey::Metric->value => $metric->value, LogContextKey::Exception->value => $exception]);
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<string>
     */
    private static function valuesOf(Metric $metric, array $labels): array
    {
        if (array_keys($labels) === $metric->labelNames()) {
            return array_values($labels);
        }

        throw new \InvalidArgumentException(\sprintf('%s takes the labels %s, in that order.', $metric->value, implode(', ', $metric->labelNames())));
    }
}
