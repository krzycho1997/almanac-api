<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Discovery;

use App\Briefing\Contract\DiscoveryCatalog;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryView;
use App\Briefing\Discovery\DiscoveryRecord;
use App\Briefing\Discovery\DoctrineDiscoveryCatalog;
use App\Briefing\Repository\DiscoveryRepository;
use App\Tests\Support\Briefing\DiscoveryCatalogContractTestCase;
use App\Tests\Support\ExposingKernel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DiscoveryCatalogTest extends DiscoveryCatalogContractTestCase
{
    private const string ADAMS = '02b1c5c6-17a8-58c7-ad26-1ca3ef19e4d5';
    private const string DEBUG_DATA = 'doctrine.debug_data_holder';
    private const string CONNECTION = 'default';
    private const string STORED_AT = '2026-11-20 10:00:00+00:00';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->container = ExposingKernel::booted('test', [DoctrineDiscoveryCatalog::class, DiscoveryRepository::class, Connection::class, self::DEBUG_DATA]);
    }

    public function testFindsManyDiscoveriesInOneQuery(): void
    {
        // Given
        $catalog = $this->catalogWith(...self::views());
        $this->debugData()->reset();

        // When
        $catalog->findMany(DiscoveryId::fromString(self::CAESAR), DiscoveryId::fromString(self::UNKNOWN), DiscoveryId::fromString(self::EVENT));

        // Then
        self::assertCount(1, $this->debugData()->getData()[self::CONNECTION] ?? []);
    }

    public function testAsksNothingForNoIds(): void
    {
        // Given
        $catalog = $this->catalogWith(...self::views());
        $this->debugData()->reset();

        // When
        $catalog->findMany();

        // Then
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

    protected function catalogWith(DiscoveryView ...$views): DiscoveryCatalog
    {
        $this->repository()->upsertDiscoveries(array_values(array_map(self::record(...), $views)), new \DateTimeImmutable(self::STORED_AT));

        return $this->catalog();
    }

    private static function record(DiscoveryView $view): DiscoveryRecord
    {
        return new DiscoveryRecord($view->id, $view->type->value.':'.$view->id->toRfc4122(), $view->type, $view->date, $view->title, $view->summary, $view->url);
    }

    private function catalog(): DiscoveryCatalog
    {
        $catalog = $this->service(DoctrineDiscoveryCatalog::class);
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
