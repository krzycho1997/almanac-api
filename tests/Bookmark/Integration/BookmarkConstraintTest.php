<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookmarkConstraintTest extends KernelTestCase
{
    private const string CHECK_VIOLATION = '23514';
    private const string VERSION_7 = '019ad6a4-2c3e-7b1f-8a2d-4e5f6a7b8c9d';
    private const string OTHER_VERSION_7 = '019ad6a4-2c3e-7b1f-8a2d-4e5f6a7b8c9e';
    private const string VERSION_5 = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    private const string VERSION_4 = '9b2e1c4a-3f6d-4a8b-9c1e-2d7f5a6b8c90';
    private const string CREATED_AT = '2026-11-18 12:00:00+00';

    /**
     * @param array<string, string> $row
     */
    #[DataProvider('rowsWithAWrongIdVersion')]
    public function testRefusesAnIdOfTheWrongVersion(array $row): void
    {
        // Given
        $connection = self::getContainer()->get(Connection::class);

        // When
        try {
            $connection->insert('bookmark', $row + ['created_at' => self::CREATED_AT]);
            self::fail('The bookmark table took an id of the wrong version.');
        } catch (DriverException $exception) {
            $state = $exception->getSQLState();
        }

        // Then
        self::assertSame(self::CHECK_VIOLATION, $state);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function rowsWithAWrongIdVersion(): iterable
    {
        yield 'bookmark id of version 4' => [['id' => self::VERSION_4, 'user_id' => self::VERSION_7, 'discovery_id' => self::VERSION_5]];
        yield 'user id of version 4' => [['id' => self::VERSION_7, 'user_id' => self::VERSION_4, 'discovery_id' => self::VERSION_5]];
        yield 'discovery id of version 7' => [['id' => self::VERSION_7, 'user_id' => self::OTHER_VERSION_7, 'discovery_id' => self::OTHER_VERSION_7]];
    }
}
