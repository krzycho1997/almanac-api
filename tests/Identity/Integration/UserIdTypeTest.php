<?php

declare(strict_types=1);

namespace App\Tests\Identity\Integration;

use App\Identity\Contract\UserId;
use App\Identity\Contract\UserIdType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserIdTypeTest extends KernelTestCase
{
    private const string ROUND_TRIP = 'SELECT CAST(:id AS uuid)';

    public function testStoresAUserIdAsAPostgresUuidAndReadsItBackAsAUserId(): void
    {
        // Given
        $connection = self::getContainer()->get(Connection::class);
        $id = new UserId();

        // When
        $stored = $connection->fetchOne(self::ROUND_TRIP, ['id' => $id], ['id' => UserIdType::NAME]);
        $read = $connection->convertToPHPValue($stored, UserIdType::NAME);

        // Then
        self::assertSame($id->toRfc4122(), $stored);
        self::assertInstanceOf(UserId::class, $read);
        self::assertTrue($id->equals($read));
    }
}
