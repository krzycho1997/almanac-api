<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Discovery;

use App\Briefing\Contract\DiscoveryCatalog;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Contract\DiscoveryView;
use App\Briefing\Discovery\DiscoveryRecord;
use App\Briefing\Discovery\DoctrineDiscoveryCatalog;
use App\Briefing\Repository\DiscoveryRepository;
use App\Tests\Support\ExposingKernel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DiscoveryCatalogTest extends TestCase
{
    private const string PICTURE = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    private const string CAESAR = '30ad2d96-39f2-514a-806e-58f2041d1ce6';
    private const string EVENT = '1604e20a-544e-5ab3-97a4-e7861e6ea31a';
    private const string UNKNOWN = '2ed6657d-e927-568b-95e1-2665a8aea6a2';
    private const string ADAMS = '02b1c5c6-17a8-58c7-ad26-1ca3ef19e4d5';
    private const string DEBUG_DATA = 'doctrine.debug_data_holder';
    private const string CONNECTION = 'default';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = ExposingKernel::booted('test', [DiscoveryCatalog::class, DiscoveryRepository::class, Connection::class, self::DEBUG_DATA]);
        $this->repository()->upsertDiscoveries([
            new DiscoveryRecord(DiscoveryId::fromString(self::PICTURE), 'apod:2024-03-15', DiscoveryType::Apod, new \DateTimeImmutable('2024-03-15'), 'Portrait of NGC 1055', 'A galaxy.', 'https://apod.nasa.gov/a.jpg'),
            new DiscoveryRecord(DiscoveryId::fromString(self::CAESAR), 'person:Q1048', DiscoveryType::Person, null, 'Julius Caesar', 'Roman dictator', 'https://en.wikipedia.org/wiki/Julius_Caesar'),
            new DiscoveryRecord(DiscoveryId::fromString(self::EVENT), 'event:1939-03-15:55ad9960237be57d', DiscoveryType::Event, new \DateTimeImmutable('1939-03-15'), 'Germany occupies Bohemia.', null, null),
        ], new \DateTimeImmutable('2026-11-20 10:00:00+00:00'));
    }

    public function testDescribesAStoredDiscovery(): void
    {
        // When
        $view = $this->catalog()->find(DiscoveryId::fromString(self::PICTURE));

        // Then
        self::assertEquals(new DiscoveryView(DiscoveryId::fromString(self::PICTURE), DiscoveryType::Apod, new \DateTimeImmutable('2024-03-15'), 'Portrait of NGC 1055', 'A galaxy.', 'https://apod.nasa.gov/a.jpg'), $view);
    }

    public function testFindsNothingForAnIdItNeverStored(): void
    {
        // When
        $view = $this->catalog()->find(DiscoveryId::fromString(self::UNKNOWN));

        // Then
        self::assertNull($view);
    }

    public function testFindsManyDiscoveriesInOneQueryAndLeavesOutUnknownIds(): void
    {
        // Given
        $this->debugData()->reset();

        // When
        $views = $this->catalog()->findMany(DiscoveryId::fromString(self::CAESAR), DiscoveryId::fromString(self::UNKNOWN), DiscoveryId::fromString(self::EVENT));

        // Then
        self::assertEqualsCanonicalizing([self::CAESAR, self::EVENT], array_keys($views));
        self::assertEquals(new DiscoveryView(DiscoveryId::fromString(self::EVENT), DiscoveryType::Event, new \DateTimeImmutable('1939-03-15'), 'Germany occupies Bohemia.', null, null), $views[self::EVENT]);
        self::assertSame(['Roman dictator', 'https://en.wikipedia.org/wiki/Julius_Caesar'], [$views[self::CAESAR]->summary, $views[self::CAESAR]->url]);
        self::assertCount(1, $this->debugData()->getData()[self::CONNECTION] ?? []);
    }

    public function testAsksNothingForNoIds(): void
    {
        // Given
        $this->debugData()->reset();

        // When
        $views = $this->catalog()->findMany();

        // Then
        self::assertSame([], $views);
        self::assertSame([], $this->debugData()->getData()[self::CONNECTION] ?? []);
    }

    #[DataProvider('incompleteData')]
    public function testDescribesADiscoveryWhoseDataLacksAField(string $data): void
    {
        // Given
        $this->connection()->insert('briefing_discovery', ['id' => self::ADAMS, 'key' => 'person:Q42', 'type' => 'person', 'date' => null, 'title' => 'Douglas Adams', 'data' => $data, 'created_at' => '2026-11-20 10:00:00+00']);

        // When
        $view = $this->catalog()->find(DiscoveryId::fromString(self::ADAMS));

        // Then
        self::assertSame([null, null], [$view?->summary, $view?->url]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function incompleteData(): iterable
    {
        yield 'no fields' => ['{}'];
        yield 'fields that are not text' => ['{"summary": 42, "url": []}'];
    }

    private function catalog(): DiscoveryCatalog
    {
        $catalog = $this->service(DiscoveryCatalog::class);
        self::assertInstanceOf(DoctrineDiscoveryCatalog::class, $catalog);

        return $catalog;
    }

    private function repository(): DiscoveryRepository
    {
        $repository = $this->service(DiscoveryRepository::class);
        self::assertInstanceOf(DiscoveryRepository::class, $repository);

        return $repository;
    }

    private function connection(): Connection
    {
        $connection = $this->service(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function debugData(): DebugDataHolder
    {
        $debugData = $this->service(self::DEBUG_DATA);
        self::assertInstanceOf(DebugDataHolder::class, $debugData);

        return $debugData;
    }

    private function service(string $id): object
    {
        return $this->container->get($id);
    }
}
