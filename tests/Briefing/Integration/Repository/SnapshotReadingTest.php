<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Repository;

use App\Briefing\FailureKind;
use App\Briefing\Repository\SourceSnapshotRepository;
use App\Briefing\SnapshotScope;
use App\Briefing\SnapshotState;
use App\Briefing\SourceEntry;
use App\Briefing\SourceFailure;
use App\Briefing\SourceName;
use App\Briefing\SourcePayloadCodec;
use App\Briefing\SourceResult;
use App\Tests\Support\Briefing\SourcePayloads;
use App\Tests\Support\ExposingKernel;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class SnapshotReadingTest extends TestCase
{
    private const string TEN = '2026-11-20 10:00:00+00:00';
    private const string ELEVEN = '2026-11-20 11:00:00+00:00';
    private const string UNREADABLE = '{"title": 42}';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = ExposingKernel::booted('test', [SourceSnapshotRepository::class, Connection::class, EntityManagerInterface::class, SourcePayloadCodec::class]);
    }

    public function testFindsNothingForScopesNeverStored(): void
    {
        // When
        $stored = $this->repository()->findForScopes([self::apod()]);

        // Then
        self::assertSame([], $stored);
    }

    public function testReadsAStoredSuccessBackAsFinalData(): void
    {
        // Given
        $this->repository()->saveResult(SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), self::apod(), self::at(self::TEN));

        // When
        $snapshot = $this->repository()->findForScopes([self::apod()])[SourceName::Apod->value];

        // Then
        self::assertEquals(SourcePayloads::apodPicture(), $snapshot->payload);
        self::assertEquals([self::at(self::TEN), self::at(self::TEN), null], [$snapshot->fetchedAt, $snapshot->lastAttemptAt, $snapshot->lastError]);
        self::assertEquals(SnapshotState::fetchedAt(self::at(self::TEN)), $snapshot->state());
        self::assertEquals(SourceEntry::fresh(SourceName::Apod, SourcePayloads::apodPicture(), self::at(self::TEN)), $snapshot->entry(false));
    }

    public function testReadsAFailureAfterASuccessWithTheOldData(): void
    {
        // Given
        $this->repository()->saveResult(SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), self::apod(), self::at(self::TEN));
        $this->repository()->saveResult(SourceResult::failed(SourceName::Apod, FailureKind::RateLimited), self::apod(), self::at(self::ELEVEN));

        // When
        $snapshot = $this->repository()->findForScopes([self::apod()])[SourceName::Apod->value];

        // Then
        self::assertEquals(SnapshotState::failedAt(self::at(self::ELEVEN), true), $snapshot->state());
        self::assertSame(FailureKind::RateLimited, $snapshot->lastError);
        self::assertEquals(self::at(self::TEN), $snapshot->fetchedAt);
        self::assertEquals(SourceEntry::stale(SourceName::Apod, SourcePayloads::apodPicture(), self::at(self::TEN), new SourceFailure(FailureKind::RateLimited), self::at(self::ELEVEN)), $snapshot->entry(false));
        self::assertEquals(SourceEntry::fresh(SourceName::Apod, SourcePayloads::apodPicture(), self::at(self::TEN)), $snapshot->entry(true));
    }

    public function testReadsAFailureWithoutDataAsMissing(): void
    {
        // Given
        $this->repository()->saveResult(SourceResult::failed(SourceName::Apod, FailureKind::Unavailable), self::apod(), self::at(self::TEN));

        // When
        $snapshot = $this->repository()->findForScopes([self::apod()])[SourceName::Apod->value];

        // Then
        self::assertEquals(SnapshotState::failedAt(self::at(self::TEN), false), $snapshot->state());
        self::assertEquals(SourceEntry::missing(SourceName::Apod, new SourceFailure(FailureKind::Unavailable), self::at(self::TEN)), $snapshot->entry(false));
    }

    public function testTreatsAnUnreadableSuccessAsNoSnapshotSoItIsFetchedAgain(): void
    {
        // Given
        $this->storeRaw('ok', null, self::UNREADABLE);

        // When
        $snapshot = $this->repository()->findForScopes([self::apod()])[SourceName::Apod->value];

        // Then
        self::assertNull($snapshot->payload);
        self::assertNull($snapshot->state());
        self::assertEquals(SourceEntry::missing(SourceName::Apod, new SourceFailure(FailureKind::InvalidPayload), self::at(self::TEN)), $snapshot->entry(false));
    }

    public function testWarnsWhenStoredDataNoLongerMatchesTheModel(): void
    {
        // Given
        $this->storeRaw('ok', null, self::UNREADABLE);
        $logs = new TestHandler();
        $repository = new SourceSnapshotRepository($this->entityManager(), $this->codec(), new Logger('briefing', [$logs]));

        // When
        $repository->findForScopes([self::apod()]);

        // Then
        self::assertCount(1, $logs->getRecords());
        self::assertSame(Level::Warning, $logs->getRecords()[0]->level);
        self::assertSame(['source' => 'apod', 'date' => '2024-03-15'], $logs->getRecords()[0]->context);
    }

    public function testKeepsThePauseOfAFailureWhoseOldDataIsUnreadable(): void
    {
        // Given
        $this->storeRaw('failed', FailureKind::Unavailable->value, self::UNREADABLE);

        // When
        $snapshot = $this->repository()->findForScopes([self::apod()])[SourceName::Apod->value];

        // Then
        self::assertEquals(SnapshotState::failedAt(self::at(self::TEN), false), $snapshot->state());
    }

    public function testReadsOnlyTheRequestedScopes(): void
    {
        // Given
        $weather = new SnapshotScope(SourceName::Weather, '2024-03-15@52.23,21.01', new \DateTimeImmutable('2024-03-15'));
        $otherDay = new SnapshotScope(SourceName::Apod, '2024-03-16', new \DateTimeImmutable('2024-03-16'));
        $this->repository()->saveResult(SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), $otherDay, self::at(self::TEN));
        $this->repository()->saveResult(SourceResult::ok(SourceName::Weather, SourcePayloads::dailyWeather()), $weather, self::at(self::TEN));
        $this->repository()->saveResult(SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), new SnapshotScope(SourceName::Apod, '2024-03-15@52.23,21.01', new \DateTimeImmutable('2024-03-15')), self::at(self::TEN));

        // When
        $stored = $this->repository()->findForScopes([self::apod(), $weather]);

        // Then
        self::assertSame([SourceName::Weather->value], array_keys($stored));
        self::assertEquals(SourcePayloads::dailyWeather(), $stored[SourceName::Weather->value]->payload);
    }

    public function testSavesSeveralResultsTogether(): void
    {
        // Given
        $weather = new SnapshotScope(SourceName::Weather, '2024-03-15@52.23,21.01', new \DateTimeImmutable('2024-03-15'));
        $results = [SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), SourceResult::failed(SourceName::Weather, FailureKind::Rejected)];

        // When
        $this->repository()->saveResults($results, [SourceName::Apod->value => self::apod(), SourceName::Weather->value => $weather], self::at(self::TEN));

        // Then
        $stored = $this->repository()->findForScopes([self::apod(), $weather]);
        self::assertEquals([SourcePayloads::apodPicture(), FailureKind::Rejected], [$stored[SourceName::Apod->value]->payload, $stored[SourceName::Weather->value]->lastError]);
    }

    public function testRefusesToSaveAResultWithoutItsScope(): void
    {
        // Then / When
        $this->expectException(\InvalidArgumentException::class);
        $this->repository()->saveResults([SourceResult::failed(SourceName::Weather, FailureKind::Rejected)], [SourceName::Apod->value => self::apod()], self::at(self::TEN));
    }

    public function testSavesNothingWhenOneOfTheResultsCannotBeSaved(): void
    {
        // Given
        $results = [SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), SourceResult::failed(SourceName::Weather, FailureKind::Rejected)];

        // When
        try {
            $this->repository()->saveResults($results, [SourceName::Apod->value => self::apod()], self::at(self::TEN));
            self::fail('A result without its scope must not be saved.');
        } catch (\InvalidArgumentException) {
        }

        // Then
        self::assertSame([], $this->repository()->findForScopes([self::apod()]));
    }

    public function testFindsNothingWithoutScopes(): void
    {
        // Given
        $this->repository()->saveResult(SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), self::apod(), self::at(self::TEN));

        // When / Then
        self::assertSame([], $this->repository()->findForScopes([]));
    }

    public function testReadsTheRowAgainAfterItChangedInTheDatabase(): void
    {
        // Given
        $this->repository()->saveResult(SourceResult::ok(SourceName::Apod, SourcePayloads::apodPicture()), self::apod(), self::at(self::TEN));
        $this->repository()->findForScopes([self::apod()]);
        $this->repository()->saveResult(SourceResult::failed(SourceName::Apod, FailureKind::Unavailable), self::apod(), self::at(self::ELEVEN));

        // When
        $snapshot = $this->repository()->findForScopes([self::apod()])[SourceName::Apod->value];

        // Then
        self::assertEquals([FailureKind::Unavailable, self::at(self::ELEVEN)], [$snapshot->lastError, $snapshot->lastAttemptAt]);
    }

    private function storeRaw(string $status, ?string $error, string $payload): void
    {
        $connection = $this->service(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->insert('briefing_source_snapshot', [
            'source' => SourceName::Apod->value,
            'scope_key' => self::apod()->key,
            'date' => '2024-03-15',
            'status' => $status,
            'error' => $error,
            'payload' => $payload,
            'fetched_at' => self::TEN,
            'last_attempt_at' => self::TEN,
        ]);
    }

    private function repository(): SourceSnapshotRepository
    {
        $repository = $this->service(SourceSnapshotRepository::class);
        self::assertInstanceOf(SourceSnapshotRepository::class, $repository);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->service(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function codec(): SourcePayloadCodec
    {
        $codec = $this->service(SourcePayloadCodec::class);
        self::assertInstanceOf(SourcePayloadCodec::class, $codec);

        return $codec;
    }

    private function service(string $id): object
    {
        return $this->container->get($id);
    }

    private static function apod(): SnapshotScope
    {
        return new SnapshotScope(SourceName::Apod, '2024-03-15', new \DateTimeImmutable('2024-03-15'));
    }

    private static function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }
}
