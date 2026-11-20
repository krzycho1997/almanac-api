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
        if ($result->failure instanceof SourceFailure) {
            $this->logger->log($result->failure->kind->logLevel(), self::MESSAGE, self::contextOf($result->source, $result->failure, $date, $durationMs));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function contextOf(SourceName $source, SourceFailure $failure, \DateTimeImmutable $date, int $durationMs): array
    {
        return array_filter([
            LogContextKey::Source->value => $source->value,
            LogContextKey::Date->value => $date->format(BriefingDay::FORMAT),
            LogContextKey::Kind->value => $failure->kind->value,
            LogContextKey::HttpStatus->value => $failure->httpStatus,
            LogContextKey::RetryAt->value => $failure->retryAt,
            LogContextKey::ErrorType->value => $failure->cause?->errorType,
            LogContextKey::Fields->value => $failure->cause?->fields,
            LogContextKey::Reason->value => $failure->cause?->defect?->value,
            LogContextKey::DurationMs->value => $durationMs,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
