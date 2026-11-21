<?php

declare(strict_types=1);

namespace App\Briefing\CircuitBreaker;

use App\Briefing\FailureKind;
use App\Briefing\SourceName;
use App\Shared\Logging\LogContextKey;
use App\Shared\Metrics\Metric;
use App\Shared\Metrics\MetricLabel;
use App\Shared\Metrics\Metrics;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

#[WithMonologChannel(self::CHANNEL)]
final readonly class CircuitLog
{
    private const string CHANNEL = 'briefing';
    private const string OPENED = 'Circuit {source} opened for {openSeconds}s';
    private const string HALF_OPEN = 'Circuit {source} half-open, trial allowed';
    private const string CLOSED = 'Circuit {source} closed after its trial';
    private const string TO_OPEN = 'open';
    private const string TO_HALF_OPEN = 'half_open';
    private const string TO_CLOSED = 'closed';

    public function __construct(
        private LoggerInterface $logger,
        private Metrics $metrics,
    ) {
    }

    public function opened(SourceName $source, FailureKind $kind, ?int $failures, int $openSeconds, bool $trial): void
    {
        $this->logger->warning(self::OPENED, self::openingOf($source, $kind, $failures, $openSeconds, $trial));
        $this->count($source, self::TO_OPEN);
    }

    public function halfOpened(SourceName $source): void
    {
        $this->logger->info(self::HALF_OPEN, [LogContextKey::Source->value => $source->value]);
        $this->count($source, self::TO_HALF_OPEN);
    }

    public function closed(SourceName $source): void
    {
        $this->logger->info(self::CLOSED, [LogContextKey::Source->value => $source->value]);
        $this->count($source, self::TO_CLOSED);
    }

    private function count(SourceName $source, string $state): void
    {
        $this->metrics->increment(Metric::CircuitTransitions, [MetricLabel::Source->value => $source->value, MetricLabel::To->value => $state]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function openingOf(SourceName $source, FailureKind $kind, ?int $failures, int $openSeconds, bool $trial): array
    {
        return array_filter([
            LogContextKey::Source->value => $source->value,
            LogContextKey::Kind->value => $kind->value,
            LogContextKey::Failures->value => $failures,
            LogContextKey::OpenSeconds->value => $openSeconds,
            LogContextKey::Trial->value => $trial,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
