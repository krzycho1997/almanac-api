<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryIdType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DiscoveryIdTypeTest extends KernelTestCase
{
    private const string EXAMPLE = '2ed6657d-e927-568b-95e1-2665a8aea6a2';
    private const string ROUND_TRIP = 'SELECT CAST(:id AS uuid)';

    public function testStoresADiscoveryIdAsAPostgresUuidAndReadsItBackAsADiscoveryId(): void
    {
        // Given
        $connection = self::getContainer()->get(Connection::class);
        $id = new DiscoveryId(self::EXAMPLE);

        // When
        $stored = $connection->fetchOne(self::ROUND_TRIP, ['id' => $id], ['id' => DiscoveryIdType::NAME]);
        $read = $connection->convertToPHPValue($stored, DiscoveryIdType::NAME);

        // Then
        self::assertSame($id->toRfc4122(), $stored);
        self::assertInstanceOf(DiscoveryId::class, $read);
        self::assertTrue($id->equals($read));
    }
}
