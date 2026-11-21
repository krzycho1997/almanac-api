<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use App\Briefing\Contract\DiscoveryCatalog;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Contract\DiscoveryView;
use PHPUnit\Framework\TestCase;

abstract class DiscoveryCatalogContractTestCase extends TestCase
{
    protected const string PICTURE = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    protected const string CAESAR = '30ad2d96-39f2-514a-806e-58f2041d1ce6';
    protected const string EVENT = '1604e20a-544e-5ab3-97a4-e7861e6ea31a';
    protected const string UNKNOWN = '2ed6657d-e927-568b-95e1-2665a8aea6a2';

    public function testDescribesAStoredDiscovery(): void
    {
        // Given
        $catalog = $this->catalogWith(...self::views());

        // When
        $view = $catalog->find(DiscoveryId::fromString(self::PICTURE));

        // Then
        self::assertEquals(self::picture(), $view);
    }

    public function testFindsNothingForAnIdItNeverStored(): void
    {
        // Given
        $catalog = $this->catalogWith(...self::views());

        // When
        $view = $catalog->find(DiscoveryId::fromString(self::UNKNOWN));

        // Then
        self::assertNull($view);
    }

    public function testKeysTheFoundDiscoveriesByTheirIdsAndLeavesOutUnknownOnes(): void
    {
        // Given
        $catalog = $this->catalogWith(...self::views());

        // When
        $views = $catalog->findMany(DiscoveryId::fromString(self::CAESAR), DiscoveryId::fromString(self::UNKNOWN), DiscoveryId::fromString(self::EVENT));

        // Then
        self::assertEqualsCanonicalizing([self::CAESAR, self::EVENT], array_keys($views));
        self::assertEquals(self::caesar(), $views[self::CAESAR]);
        self::assertEquals(self::event(), $views[self::EVENT]);
    }

    public function testFindsNothingForNoIds(): void
    {
        // Given
        $catalog = $this->catalogWith(...self::views());

        // When
        $views = $catalog->findMany();

        // Then
        self::assertSame([], $views);
    }

    public function testFindsEveryStoredDiscoveryAloneAndInABatchAlike(): void
    {
        // Given
        $catalog = $this->catalogWith(...self::views());

        // When
        $found = array_map(static fn (DiscoveryView $view): array => [$catalog->find($view->id), $catalog->findMany($view->id)[$view->id->toRfc4122()] ?? null], self::views());

        // Then
        self::assertEquals(array_map(static fn (DiscoveryView $view): array => [$view, $view], self::views()), $found);
    }

    public function testFindsARepeatedIdOnce(): void
    {
        // Given
        $catalog = $this->catalogWith(...self::views());

        // When
        $views = $catalog->findMany(DiscoveryId::fromString(self::CAESAR), DiscoveryId::fromString(self::CAESAR));

        // Then
        self::assertSame([self::CAESAR], array_keys($views));
    }

    public function testFindsNothingInAnEmptyCatalog(): void
    {
        // Given
        $catalog = $this->catalogWith();

        // When
        $found = [$catalog->find(DiscoveryId::fromString(self::PICTURE)), $catalog->findMany(DiscoveryId::fromString(self::PICTURE))];

        // Then
        self::assertSame([null, []], $found);
    }

    abstract protected function catalogWith(DiscoveryView ...$views): DiscoveryCatalog;

    /**
     * @return list<DiscoveryView>
     */
    protected static function views(): array
    {
        return [self::picture(), self::caesar(), self::event()];
    }

    private static function picture(): DiscoveryView
    {
        return new DiscoveryView(DiscoveryId::fromString(self::PICTURE), DiscoveryType::Apod, new \DateTimeImmutable('2024-03-15'), 'Portrait of NGC 1055', 'A galaxy.', 'https://apod.nasa.gov/a.jpg');
    }

    private static function caesar(): DiscoveryView
    {
        return new DiscoveryView(DiscoveryId::fromString(self::CAESAR), DiscoveryType::Person, null, 'Julius Caesar', 'Roman dictator', 'https://en.wikipedia.org/wiki/Julius_Caesar');
    }

    private static function event(): DiscoveryView
    {
        return new DiscoveryView(DiscoveryId::fromString(self::EVENT), DiscoveryType::Event, new \DateTimeImmutable('1939-03-15'), 'Germany occupies Bohemia.', null, null);
    }
}
