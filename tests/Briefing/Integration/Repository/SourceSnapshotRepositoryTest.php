<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Repository;

use App\Briefing\Entity\SourceSnapshot;
use App\Briefing\FailureKind;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\WeatherKind;
use App\Briefing\Repository\SnapshotWrite;
use App\Briefing\Repository\SourceSnapshotRepository;
use App\Briefing\SnapshotScope;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Briefing\SourceStatus;
use App\Tests\Support\ExposingKernel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class SourceSnapshotRepositoryTest extends TestCase
{
    private const string DAY = '2024-03-15';
    private const string KEY = '2024-03-15@52.23,21.01';
    private const string TEN = '2026-11-20 10:00:00+00:00';
    private const string TEN_TWO = '2026-11-20 10:02:00+00:00';
    private const string TEN_FIVE = '2026-11-20 10:05:00+00:00';
    private const string TEN_SEVEN = '2026-11-20 10:07:00+00:00';
    private const string TEN_FIVE_IN_WARSAW = '2026-11-20 11:05:00+01:00';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = ExposingKernel::booted('test', [SourceSnapshotRepository::class, EntityManagerInterface::class]);
    }

    public function testStoresTheFirstSuccess(): void
    {
        // When
        $write = $this->repository()->saveResult(self::success(12.0), self::scope(), self::at(self::TEN));

        // Then
        $snapshot = $this->stored();
        self::assertSame(SnapshotWrite::Saved, $write);
        self::assertSame([SourceStatus::Ok, null, self::DAY], [$snapshot->status, $snapshot->error, $snapshot->date?->format('Y-m-d')]);
        self::assertSame(12.0, $snapshot->payload['temperatureMax'] ?? null);
        self::assertEquals([self::at(self::TEN), self::at(self::TEN)], [$snapshot->fetchedAt, $snapshot->lastAttemptAt]);
    }

    public function testStoresTheFirstFailureWithoutData(): void
    {
        // When
        $write = $this->repository()->saveResult(self::failure(), self::scope(), self::at(self::TEN));

        // Then
        $snapshot = $this->stored();
        self::assertSame(SnapshotWrite::Saved, $write);
        self::assertSame([SourceStatus::Failed, FailureKind::Unavailable, null, null], [$snapshot->status, $snapshot->error, $snapshot->payload, $snapshot->fetchedAt]);
        self::assertEquals(self::at(self::TEN), $snapshot->lastAttemptAt);
    }

    public function testKeepsTheLastGoodDataWhenARefreshFails(): void
    {
        // Given
        $this->repository()->saveResult(self::success(12.0), self::scope(), self::at(self::TEN));

        // When
        $write = $this->repository()->saveResult(self::failure(), self::scope(), self::at(self::TEN_FIVE));

        // Then
        $snapshot = $this->stored();
        self::assertSame(SnapshotWrite::Saved, $write);
        self::assertSame([SourceStatus::Failed, FailureKind::Unavailable, 12.0], [$snapshot->status, $snapshot->error, $snapshot->payload['temperatureMax'] ?? null]);
        self::assertEquals([self::at(self::TEN), self::at(self::TEN_FIVE)], [$snapshot->fetchedAt, $snapshot->lastAttemptAt]);
    }

    public function testReplacesAFailureWithANewerSuccess(): void
    {
        // Given
        $this->repository()->saveResult(self::failure(), self::scope(), self::at(self::TEN));

        // When
        $write = $this->repository()->saveResult(self::success(14.0), self::scope(), self::at(self::TEN_FIVE));

        // Then
        $snapshot = $this->stored();
        self::assertSame(SnapshotWrite::Saved, $write);
        self::assertSame([SourceStatus::Ok, null, 14.0], [$snapshot->status, $snapshot->error, $snapshot->payload['temperatureMax'] ?? null]);
        self::assertEquals([self::at(self::TEN_FIVE), self::at(self::TEN_FIVE)], [$snapshot->fetchedAt, $snapshot->lastAttemptAt]);
    }

    public function testLetsASuccessWinOverAFailureInTheSameSecond(): void
    {
        // Given
        $this->repository()->saveResult(self::failure(), self::scope(), self::at(self::TEN_FIVE));

        // When
        $write = $this->repository()->saveResult(self::success(14.0), self::scope(), self::at(self::TEN_FIVE));

        // Then
        $snapshot = $this->stored();
        self::assertSame(SnapshotWrite::Saved, $write);
        self::assertSame([SourceStatus::Ok, null, 14.0], [$snapshot->status, $snapshot->error, $snapshot->payload['temperatureMax'] ?? null]);
    }

    public function testReplacesAFailureWithANewerFailure(): void
    {
        // Given
        $this->repository()->saveResult(self::failure(), self::scope(), self::at(self::TEN));

        // When
        $write = $this->repository()->saveResult(self::failure(FailureKind::RateLimited), self::scope(), self::at(self::TEN_FIVE));

        // Then
        $snapshot = $this->stored();
        self::assertSame(SnapshotWrite::Saved, $write);
        self::assertSame([SourceStatus::Failed, FailureKind::RateLimited], [$snapshot->status, $snapshot->error]);
        self::assertEquals(self::at(self::TEN_FIVE), $snapshot->lastAttemptAt);
    }

    #[DataProvider('writesInWarsaw')]
    public function testComparesMomentsWrittenInAnotherTimeZone(SourceResult $earlier, SourceResult $later): void
    {
        // Given
        $this->repository()->saveResult($earlier, self::scope(), self::at(self::TEN_FIVE_IN_WARSAW));

        // When
        $write = $this->repository()->saveResult($later, self::scope(), self::at(self::TEN_TWO));

        // Then
        self::assertSame(SnapshotWrite::KeptNewer, $write);
        self::assertEquals(self::at(self::TEN_FIVE), $this->stored()->lastAttemptAt);
    }

    /**
     * @return iterable<string, array{SourceResult, SourceResult}>
     */
    public static function writesInWarsaw(): iterable
    {
        yield 'success' => [self::success(14.0), self::success(12.0)];
        yield 'failure' => [self::failure(), self::failure(FailureKind::Rejected)];
    }

    #[DataProvider('failureKinds')]
    public function testStoresEveryKindOfFailure(FailureKind $kind): void
    {
        // When
        $this->repository()->saveResult(self::failure($kind), self::scope(), self::at(self::TEN));

        // Then
        self::assertSame($kind, $this->stored()->error);
    }

    /**
     * @return iterable<string, array{FailureKind}>
     */
    public static function failureKinds(): iterable
    {
        foreach (FailureKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    #[DataProvider('sources')]
    public function testStoresAFailureOfEverySource(SourceName $source): void
    {
        // Given
        $scope = new SnapshotScope($source, 'key-of-'.$source->value, self::dateFor($source));

        // When
        $this->repository()->saveResult(SourceResult::failed($source, FailureKind::Unavailable), $scope, self::at(self::TEN));

        // Then
        self::assertSame($source, $this->stored($source, 'key-of-'.$source->value)->source);
    }

    /**
     * @return iterable<string, array{SourceName}>
     */
    public static function sources(): iterable
    {
        foreach (SourceName::cases() as $source) {
            yield $source->value => [$source];
        }
    }

    #[DataProvider('olderOrSimultaneousWrites')]
    public function testKeepsNewerDataWhenAnOlderOrSimultaneousWriteArrivesLate(SourceResult $late, string $lateAt): void
    {
        // Given
        $this->repository()->saveResult(self::success(14.0), self::scope(), self::at(self::TEN_FIVE));

        // When
        $write = $this->repository()->saveResult($late, self::scope(), self::at($lateAt));

        // Then
        $snapshot = $this->stored();
        self::assertSame(SnapshotWrite::KeptNewer, $write);
        self::assertSame([SourceStatus::Ok, 14.0], [$snapshot->status, $snapshot->payload['temperatureMax'] ?? null]);
        self::assertEquals([self::at(self::TEN_FIVE), self::at(self::TEN_FIVE)], [$snapshot->fetchedAt, $snapshot->lastAttemptAt]);
    }

    /**
     * @return iterable<string, array{SourceResult, string}>
     */
    public static function olderOrSimultaneousWrites(): iterable
    {
        yield 'older success' => [self::success(12.0), self::TEN];
        yield 'older failure' => [self::failure(), self::TEN];
        yield 'success in the same second' => [self::success(12.0), self::TEN_FIVE];
        yield 'failure in the same second' => [self::failure(), self::TEN_FIVE];
    }

    public function testTakesTheDataOfASlowSuccessButKeepsTheNewerFailedAttempt(): void
    {
        // Given
        $this->repository()->saveResult(self::success(12.0), self::scope(), self::at(self::TEN));
        $this->repository()->saveResult(self::failure(FailureKind::RateLimited), self::scope(), self::at(self::TEN_SEVEN));

        // When
        $write = $this->repository()->saveResult(self::success(13.0), self::scope(), self::at(self::TEN_FIVE));

        // Then
        $snapshot = $this->stored();
        self::assertSame(SnapshotWrite::Saved, $write);
        self::assertSame([SourceStatus::Failed, FailureKind::RateLimited, 13.0], [$snapshot->status, $snapshot->error, $snapshot->payload['temperatureMax'] ?? null]);
        self::assertEquals([self::at(self::TEN_FIVE), self::at(self::TEN_SEVEN)], [$snapshot->fetchedAt, $snapshot->lastAttemptAt]);
    }

    public function testIgnoresAFailureOlderThanTheLastAttemptAfterASlowSuccess(): void
    {
        // Given
        $this->repository()->saveResult(self::failure(), self::scope(), self::at(self::TEN_SEVEN));
        $this->repository()->saveResult(self::success(13.0), self::scope(), self::at(self::TEN_TWO));

        // When
        $write = $this->repository()->saveResult(self::failure(FailureKind::Rejected), self::scope(), self::at(self::TEN_FIVE));

        // Then
        $snapshot = $this->stored();
        self::assertSame(SnapshotWrite::KeptNewer, $write);
        self::assertSame([SourceStatus::Failed, FailureKind::Unavailable, 13.0], [$snapshot->status, $snapshot->error, $snapshot->payload['temperatureMax'] ?? null]);
        self::assertEquals(self::at(self::TEN_SEVEN), $snapshot->lastAttemptAt);
    }

    public function testStoresADayInHistoryWithoutADate(): void
    {
        // Given
        $feed = new OnThisDayFeed([new HistoricalEvent(-44, 'Julius Caesar is assassinated.', [])], [], [], self::attribution());

        // When
        $this->repository()->saveResult(SourceResult::ok(SourceName::OnThisDay, $feed), new SnapshotScope(SourceName::OnThisDay, '03-15', null), self::at(self::TEN));

        // Then
        $snapshot = $this->stored(SourceName::OnThisDay, '03-15');
        self::assertNull($snapshot->date);
        self::assertSame(SourceStatus::Ok, $snapshot->status);
    }

    #[DataProvider('resultsWithNothingToStore')]
    public function testRefusesAResultThatCannotBeStoredUnderTheScope(SourceResult $result): void
    {
        // Then / When
        $this->expectException(\InvalidArgumentException::class);
        $this->repository()->saveResult($result, self::scope(), self::at(self::TEN));
    }

    /**
     * @return iterable<string, array{SourceResult}>
     */
    public static function resultsWithNothingToStore(): iterable
    {
        yield 'not applicable' => [SourceResult::notApplicable(SourceName::Weather)];
        yield 'result of another source' => [SourceResult::failed(SourceName::Apod, FailureKind::Unavailable)];
    }

    private function repository(): SourceSnapshotRepository
    {
        $repository = $this->service(SourceSnapshotRepository::class);
        self::assertInstanceOf(SourceSnapshotRepository::class, $repository);

        return $repository;
    }

    private function stored(SourceName $source = SourceName::Weather, string $key = self::KEY): SourceSnapshot
    {
        $entityManager = $this->service(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        $snapshot = $entityManager->find(SourceSnapshot::class, ['source' => $source, 'scopeKey' => $key]);
        self::assertInstanceOf(SourceSnapshot::class, $snapshot);

        return $snapshot;
    }

    private function service(string $id): object
    {
        return $this->container->get($id);
    }

    private static function dateFor(SourceName $source): ?\DateTimeImmutable
    {
        return match ($source) {
            SourceName::OnThisDay => null,
            SourceName::Apod, SourceName::Weather => new \DateTimeImmutable(self::DAY),
        };
    }

    private static function scope(): SnapshotScope
    {
        return new SnapshotScope(SourceName::Weather, self::KEY, new \DateTimeImmutable(self::DAY));
    }

    private static function success(float $temperatureMax): SourceResult
    {
        return SourceResult::ok(SourceName::Weather, new DailyWeather(WeatherKind::Final, 'Europe/Warsaw', 1.0, $temperatureMax, 0.0, 10.0, 3, null, null, null, self::attribution()));
    }

    private static function failure(FailureKind $kind = FailureKind::Unavailable): SourceResult
    {
        return SourceResult::failed(SourceName::Weather, $kind);
    }

    private static function attribution(): Attribution
    {
        return new Attribution('Open-Meteo', 'https://open-meteo.com/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', true, null);
    }

    private static function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }
}
