<?php

declare(strict_types=1);

namespace App\Tests\Bookmark\Integration;

use App\Bookmark\Entity\Bookmark;
use App\Briefing\Contract\DiscoveryId;
use App\Identity\Contract\UserId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookmarkMappingTest extends KernelTestCase
{
    private const string DISCOVERY = 'c05f9b6a-9262-5859-b45d-743a62e1bc6e';
    private const string CREATED_AT = '2026-11-18 12:00:00+00:00';

    public function testStoresABookmarkAndReadsItBackWithItsTypedIds(): void
    {
        // Given
        $bookmark = new Bookmark(new UserId(), DiscoveryId::fromString(self::DISCOVERY), new \DateTimeImmutable(self::CREATED_AT));
        $entityManager = self::entityManager();
        $entityManager->persist($bookmark);
        $entityManager->flush();
        $entityManager->clear();

        // When
        $read = $entityManager->find(Bookmark::class, $bookmark->id);

        // Then
        self::assertInstanceOf(Bookmark::class, $read);
        self::assertTrue($bookmark->id->equals($read->id));
        self::assertTrue($bookmark->userId->equals($read->userId));
        self::assertTrue($bookmark->discoveryId->equals($read->discoveryId));
        self::assertEquals($bookmark->createdAt, $read->createdAt);
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
