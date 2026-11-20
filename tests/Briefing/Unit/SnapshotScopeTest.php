<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\SnapshotScope;
use App\Briefing\SourceName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SnapshotScopeTest extends TestCase
{
    #[DataProvider('keys')]
    public function testAcceptsAKeyThatFitsTheColumn(string $key): void
    {
        // When
        $scope = new SnapshotScope(SourceName::Weather, $key, null);

        // Then
        self::assertSame($key, $scope->key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keys(): iterable
    {
        yield 'one character' => ['x'];
        yield 'day and place' => ['2024-03-15@-89.99,-179.99'];
        yield 'longest' => [str_repeat('ż', SnapshotScope::LONGEST_KEY)];
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsAKeyThatDoesNotFitTheColumn(string $key): void
    {
        // Then / When
        $this->expectException(\InvalidArgumentException::class);
        new SnapshotScope(SourceName::Weather, $key, null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'one character too long' => [str_repeat('ż', SnapshotScope::LONGEST_KEY + 1)];
    }
}
