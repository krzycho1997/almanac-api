<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Contract;

use App\Briefing\Contract\DiscoveryId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use Symfony\Component\Uid\UuidV7;

final class DiscoveryIdTest extends TestCase
{
    private const string EXAMPLE = '2ed6657d-e927-568b-95e1-2665a8aea6a2';

    public function testReadsItsOwnStringBack(): void
    {
        // Given
        $id = new DiscoveryId(self::EXAMPLE);

        // When
        $read = DiscoveryId::fromString($id->toRfc4122());

        // Then
        self::assertTrue($id->equals($read));
    }

    public function testAcceptsAnyVersion5Uuid(): void
    {
        // Given
        $generic = Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_DNS), 'www.example.com');

        // When
        $read = DiscoveryId::fromString($generic->toRfc4122());

        // Then
        self::assertSame(self::EXAMPLE, $read->toRfc4122());
    }

    #[DataProvider('notVersion5')]
    public function testRejectsAnythingButAVersion5UuidWhenRead(string $uuid): void
    {
        // Then / When
        $this->expectException(\InvalidArgumentException::class);
        DiscoveryId::fromString($uuid);
    }

    #[DataProvider('notVersion5')]
    public function testRejectsAnythingButAVersion5UuidWhenBuilt(string $uuid): void
    {
        // Then / When
        $this->expectException(\InvalidArgumentException::class);
        new DiscoveryId($uuid);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notVersion5(): iterable
    {
        yield 'version 3' => [Uuid::v3(Uuid::fromString(Uuid::NAMESPACE_DNS), 'www.example.com')->toRfc4122()];
        yield 'version 4' => [new UuidV4()->toRfc4122()];
        yield 'version 7' => [new UuidV7()->toRfc4122()];
        yield 'version 5 with another variant' => ['2ed6657d-e927-568b-05e1-2665a8aea6a2'];
        yield 'nil' => [new NilUuid()->toRfc4122()];
        yield 'garbage' => ['not-a-uuid'];
    }
}
