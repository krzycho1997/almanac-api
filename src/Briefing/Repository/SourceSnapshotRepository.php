<?php

declare(strict_types=1);

namespace App\Briefing\Repository;

use App\Briefing\FailureKind;
use App\Briefing\Model\SourcePayload;
use App\Briefing\SnapshotScope;
use App\Briefing\SourcePayloadCodec;
use App\Briefing\SourceResult;
use App\Briefing\SourceStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class SourceSnapshotRepository
{
    private const string SAVE_SUCCESS = <<<'SQL'
        INSERT INTO briefing_source_snapshot AS snapshot (source, scope_key, date, status, error, payload, fetched_at, last_attempt_at)
        VALUES (:source, :scopeKey, :date, :status, NULL, :payload, :attemptedAt, :attemptedAt)
        ON CONFLICT (source, scope_key) DO UPDATE SET
            payload = EXCLUDED.payload,
            fetched_at = EXCLUDED.fetched_at,
            status = CASE WHEN snapshot.last_attempt_at > EXCLUDED.last_attempt_at THEN snapshot.status ELSE EXCLUDED.status END,
            error = CASE WHEN snapshot.last_attempt_at > EXCLUDED.last_attempt_at THEN snapshot.error END,
            last_attempt_at = GREATEST(snapshot.last_attempt_at, EXCLUDED.last_attempt_at)
        WHERE snapshot.fetched_at IS NULL OR snapshot.fetched_at < EXCLUDED.fetched_at
        SQL;

    private const string SAVE_FAILURE = <<<'SQL'
        INSERT INTO briefing_source_snapshot AS snapshot (source, scope_key, date, status, error, payload, fetched_at, last_attempt_at)
        VALUES (:source, :scopeKey, :date, :status, :error, NULL, NULL, :attemptedAt)
        ON CONFLICT (source, scope_key) DO UPDATE SET
            status = EXCLUDED.status,
            error = EXCLUDED.error,
            last_attempt_at = EXCLUDED.last_attempt_at
        WHERE snapshot.last_attempt_at < EXCLUDED.last_attempt_at
        SQL;

    private const array SUCCESS_TYPES = [
        'source' => Types::STRING,
        'scopeKey' => Types::STRING,
        'date' => Types::DATE_IMMUTABLE,
        'status' => Types::STRING,
        'payload' => Types::JSONB,
        'attemptedAt' => Types::DATETIMETZ_IMMUTABLE,
    ];

    private const array FAILURE_TYPES = [
        'source' => Types::STRING,
        'scopeKey' => Types::STRING,
        'date' => Types::DATE_IMMUTABLE,
        'status' => Types::STRING,
        'error' => Types::STRING,
        'attemptedAt' => Types::DATETIMETZ_IMMUTABLE,
    ];

    public function __construct(
        private Connection $connection,
        private SourcePayloadCodec $codec,
    ) {
    }

    public function saveResult(SourceResult $result, SnapshotScope $scope, \DateTimeImmutable $attemptedAt): SnapshotWrite
    {
        self::assertSameSource($result, $scope);

        if ($result->hasData()) {
            return $this->saveSuccess($result->payload, $scope, $attemptedAt);
        }

        if ($result->failureKind instanceof FailureKind) {
            return $this->saveFailure($result->failureKind, $scope, $attemptedAt);
        }

        throw new \InvalidArgumentException("A {$result->status->value} result of {$result->source->value} has nothing to store.");
    }

    private function saveSuccess(SourcePayload $payload, SnapshotScope $scope, \DateTimeImmutable $attemptedAt): SnapshotWrite
    {
        $parameters = self::scopeParameters($scope) + [
            'status' => SourceStatus::Ok->value,
            'payload' => $this->codec->encode($payload),
            'attemptedAt' => $attemptedAt,
        ];

        return SnapshotWrite::fromAffectedRows($this->connection->executeStatement(self::SAVE_SUCCESS, $parameters, self::SUCCESS_TYPES));
    }

    private function saveFailure(FailureKind $kind, SnapshotScope $scope, \DateTimeImmutable $attemptedAt): SnapshotWrite
    {
        $parameters = self::scopeParameters($scope) + [
            'status' => SourceStatus::Failed->value,
            'error' => $kind->value,
            'attemptedAt' => $attemptedAt,
        ];

        return SnapshotWrite::fromAffectedRows($this->connection->executeStatement(self::SAVE_FAILURE, $parameters, self::FAILURE_TYPES));
    }

    /**
     * @return array{source: string, scopeKey: string, date: ?\DateTimeImmutable}
     */
    private static function scopeParameters(SnapshotScope $scope): array
    {
        return ['source' => $scope->source->value, 'scopeKey' => $scope->key, 'date' => $scope->date];
    }

    private static function assertSameSource(SourceResult $result, SnapshotScope $scope): void
    {
        if ($result->source === $scope->source) {
            return;
        }

        throw new \InvalidArgumentException("A result of {$result->source->value} cannot be stored under a {$scope->source->value} scope.");
    }
}
