<?php

declare(strict_types=1);

namespace App\Briefing\Repository;

use App\Briefing\BriefingDay;
use App\Briefing\Entity\SourceSnapshot;
use App\Briefing\Exception\UnreadablePayloadException;
use App\Briefing\FailureKind;
use App\Briefing\Model\SourcePayload;
use App\Briefing\SnapshotScope;
use App\Briefing\SourceFailure;
use App\Briefing\SourceName;
use App\Briefing\SourcePayloadCodec;
use App\Briefing\SourceResult;
use App\Briefing\SourceStatus;
use App\Briefing\StoredSnapshot;
use App\Shared\Logging\LogContextKey;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

#[WithMonologChannel(self::CHANNEL)]
final readonly class SourceSnapshotRepository
{
    private const string CHANNEL = 'briefing';
    private const string ALIAS = 'snapshot';
    private const string UNREADABLE = 'Stored {source} snapshot no longer matches the model and is fetched again';

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
        private EntityManagerInterface $entityManager,
        private SourcePayloadCodec $codec,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<SnapshotScope> $scopes
     *
     * @return array<value-of<SourceName>, StoredSnapshot>
     */
    public function findForScopes(array $scopes): array
    {
        if ([] === $scopes) {
            return [];
        }

        return $this->storedIn($this->snapshotsFor($scopes));
    }

    /**
     * @param list<SourceResult>                         $results
     * @param array<value-of<SourceName>, SnapshotScope> $scopes
     */
    public function saveResults(array $results, array $scopes, \DateTimeImmutable $attemptedAt): void
    {
        foreach ($results as $result) {
            $this->saveResult($result, self::scopeOf($result, $scopes), $attemptedAt);
        }
    }

    public function saveResult(SourceResult $result, SnapshotScope $scope, \DateTimeImmutable $attemptedAt): SnapshotWrite
    {
        self::assertSameSource($result, $scope);
        self::assertCalledUpstream($result);

        if ($result->hasData()) {
            return $this->saveSuccess($result->payload, $scope, $attemptedAt);
        }

        if ($result->failure instanceof SourceFailure) {
            return $this->saveFailure($result->failure->kind, $scope, $attemptedAt);
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

        return SnapshotWrite::fromAffectedRows($this->connection()->executeStatement(self::SAVE_SUCCESS, $parameters, self::SUCCESS_TYPES));
    }

    private function saveFailure(FailureKind $kind, SnapshotScope $scope, \DateTimeImmutable $attemptedAt): SnapshotWrite
    {
        $parameters = self::scopeParameters($scope) + [
            'status' => SourceStatus::Failed->value,
            'error' => $kind->value,
            'attemptedAt' => $attemptedAt,
        ];

        return SnapshotWrite::fromAffectedRows($this->connection()->executeStatement(self::SAVE_FAILURE, $parameters, self::FAILURE_TYPES));
    }

    /**
     * @return array{source: string, scopeKey: string, date: ?\DateTimeImmutable}
     */
    private static function scopeParameters(SnapshotScope $scope): array
    {
        return ['source' => $scope->source->value, 'scopeKey' => $scope->key, 'date' => $scope->date];
    }

    /**
     * @param non-empty-list<SnapshotScope> $scopes
     *
     * @return list<SourceSnapshot>
     */
    private function snapshotsFor(array $scopes): array
    {
        $query = $this->entityManager->createQueryBuilder()->select(self::ALIAS)->from(SourceSnapshot::class, self::ALIAS);

        foreach ($scopes as $index => $scope) {
            $query->orWhere(self::ALIAS.".source = :source{$index} AND ".self::ALIAS.".scopeKey = :key{$index}")
                ->setParameter("source{$index}", $scope->source->value)
                ->setParameter("key{$index}", $scope->key);
        }

        return $query->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
    }

    /**
     * @param list<SourceSnapshot> $snapshots
     *
     * @return array<value-of<SourceName>, StoredSnapshot>
     */
    private function storedIn(array $snapshots): array
    {
        $stored = [];

        foreach ($snapshots as $snapshot) {
            $stored[$snapshot->source->value] = $this->stored($snapshot);
        }

        return $stored;
    }

    private function stored(SourceSnapshot $snapshot): StoredSnapshot
    {
        return new StoredSnapshot($snapshot->source, $this->payloadOf($snapshot), $snapshot->fetchedAt, $snapshot->lastAttemptAt, $snapshot->error);
    }

    private function payloadOf(SourceSnapshot $snapshot): ?SourcePayload
    {
        if (null === $snapshot->payload) {
            return null;
        }

        try {
            return $this->codec->decode($snapshot->source, $snapshot->payload);
        } catch (UnreadablePayloadException) {
            $this->logger->warning(self::UNREADABLE, [LogContextKey::Source->value => $snapshot->source->value, LogContextKey::Date->value => $snapshot->date?->format(BriefingDay::FORMAT)]);

            return null;
        }
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * @param array<value-of<SourceName>, SnapshotScope> $scopes
     */
    private static function scopeOf(SourceResult $result, array $scopes): SnapshotScope
    {
        return $scopes[$result->source->value] ?? throw new \InvalidArgumentException("No scope for the {$result->source->value} result.");
    }

    private static function assertSameSource(SourceResult $result, SnapshotScope $scope): void
    {
        if ($result->source === $scope->source) {
            return;
        }

        throw new \InvalidArgumentException("A result of {$result->source->value} cannot be stored under a {$scope->source->value} scope.");
    }

    private static function assertCalledUpstream(SourceResult $result): void
    {
        if ($result->calledUpstream()) {
            return;
        }

        throw new \InvalidArgumentException("A result of {$result->source->value} refused by its circuit has nothing to store.");
    }
}
