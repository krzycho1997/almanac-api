<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Repository;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Discovery\DiscoveryRecord;
use App\Briefing\Entity\Discovery;
use App\Briefing\Repository\DiscoveryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DiscoveryRepositoryTest extends KernelTestCase
{
    private const string FIRST_ID = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    private const string SECOND_ID = '4ab42343-d733-5a10-9272-89a550af37e3';
    private const string TEN = '2026-11-20 10:00:00+00:00';
    private const string ELEVEN = '2026-11-20 11:00:00+00:00';

    public function testStoresEachKeyOnceWithinOneWrite(): void
    {
        // Given
        $records = [self::person(self::SECOND_ID, 'person:Julius Caesar'), self::apod(), self::person(self::SECOND_ID, 'person:Julius Caesar')];

        // When
        $inserted = $this->repository()->upsertDiscoveries($records, new \DateTimeImmutable(self::TEN));

        // Then
        self::assertSame(2, $inserted);
        self::assertSame(['apod:2024-03-15', 'person:Julius Caesar'], array_map(static fn (Discovery $discovery): string => $discovery->key, $this->stored()));
    }

    public function testLeavesAKnownDiscoveryUntouched(): void
    {
        // Given
        $this->repository()->upsertDiscoveries([self::apod()], new \DateTimeImmutable(self::TEN));

        // When
        $inserted = $this->repository()->upsertDiscoveries([self::apod('Renamed')], new \DateTimeImmutable(self::ELEVEN));

        // Then
        $discovery = $this->stored()[0];
        self::assertSame(0, $inserted);
        self::assertSame(['Portrait of NGC 1055', self::TEN], [$discovery->title, $discovery->createdAt->format('Y-m-d H:i:sP')]);
        self::assertTrue(DiscoveryId::fromString(self::FIRST_ID)->equals($discovery->id));
        self::assertEquals(['url' => 'https://apod.nasa.gov/a.jpg', 'summary' => 'A galaxy.'], $discovery->data);
    }

    public function testStoresTheFirstOfTwoRecordsWithOneKeyInABatch(): void
    {
        // When
        $inserted = $this->repository()->upsertDiscoveries([self::apod(), self::apod('Renamed')], new \DateTimeImmutable(self::TEN));

        // Then
        self::assertSame(1, $inserted);
        self::assertSame(['Portrait of NGC 1055'], array_map(static fn (Discovery $discovery): string => $discovery->title, $this->stored()));
    }

    public function testSkipsADiscoveryWhoseIdAnotherWriteJustStored(): void
    {
        // Given
        $this->repository()->upsertDiscoveries([self::person(self::FIRST_ID, 'person:Q1048')], new \DateTimeImmutable(self::TEN));

        // When
        $inserted = $this->repository()->upsertDiscoveries([self::apod()], new \DateTimeImmutable(self::ELEVEN));

        // Then
        self::assertSame(0, $inserted);
    }

    private function repository(): DiscoveryRepository
    {
        return self::getContainer()->get(DiscoveryRepository::class);
    }

    /**
     * @return list<Discovery>
     */
    private function stored(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Discovery::class)->findBy([], ['key' => 'ASC']);
    }

    private static function apod(string $title = 'Portrait of NGC 1055'): DiscoveryRecord
    {
        return new DiscoveryRecord(DiscoveryId::fromString(self::FIRST_ID), 'apod:2024-03-15', DiscoveryType::Apod, new \DateTimeImmutable('2024-03-15'), $title, 'A galaxy.', 'https://apod.nasa.gov/a.jpg');
    }

    private static function person(string $id, string $key): DiscoveryRecord
    {
        return new DiscoveryRecord(DiscoveryId::fromString($id), $key, DiscoveryType::Person, null, 'Julius Caesar', null, 'https://en.wikipedia.org/wiki/Julius_Caesar');
    }
}
