<?php

declare(strict_types=1);

namespace App\Briefing;

use App\Shared\Logging\LogContextKey;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

#[WithMonologChannel(self::CHANNEL)]
final readonly class SourceFailureLog
{
    private const string CHANNEL = 'briefing';
    private const string MESSAGE = 'Source {source} fetch failed: {kind}';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function record(SourceResult $result, \DateTimeImmutable $date, int $durationMs): void
    {
        if ($result->failureKind instanceof FailureKind) {
            $this->logger->log($result->failureKind->logLevel(), self::MESSAGE, self::contextOf($result, $result->failureKind, $date, $durationMs));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function contextOf(SourceResult $result, FailureKind $kind, \DateTimeImmutable $date, int $durationMs): array
    {
        return array_filter([
            LogContextKey::Source->value => $result->source->value,
            LogContextKey::Date->value => $date->format(BriefingDay::FORMAT),
            LogContextKey::Kind->value => $kind->value,
            LogContextKey::HttpStatus->value => $result->httpStatus,
            LogContextKey::RetryAt->value => $result->retryAt,
            LogContextKey::ErrorType->value => $result->cause?->errorType,
            LogContextKey::Fields->value => $result->cause?->fields,
            LogContextKey::Reason->value => $result->cause?->defect?->value,
            LogContextKey::DurationMs->value => $durationMs,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
