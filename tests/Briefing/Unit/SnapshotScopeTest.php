<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\SnapshotScope;
use App\Briefing\SourceName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SnapshotScopeTest extends TestCase
{
    private const string KEY = '2024-03-15';
    private const string DATE = '2024-03-15';

    #[DataProvider('keys')]
    public function testAcceptsAKeyThatFitsTheColumn(string $key): void
    {
        // When
        $scope = SnapshotScope::undated(SourceName::OnThisDay, $key);

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
        SnapshotScope::undated(SourceName::OnThisDay, $key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'one character too long' => [str_repeat('ż', SnapshotScope::LONGEST_KEY + 1)];
    }

    public function testKeepsTheDateOfADatedScope(): void
    {
        // Given
        $date = new \DateTimeImmutable(self::DATE);

        // When
        $scope = SnapshotScope::dated(SourceName::Weather, self::KEY, $date);

        // Then
        self::assertSame([SourceName::Weather, self::KEY, $date], [$scope->source, $scope->key, $scope->date]);
    }

    public function testGivesAnUndatedScopeNoDate(): void
    {
        // When
        $scope = SnapshotScope::undated(SourceName::OnThisDay, self::KEY);

        // Then
        self::assertNull($scope->date);
    }
}
