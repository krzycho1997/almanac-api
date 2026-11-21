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
        $scope = new SnapshotScope(SourceName::OnThisDay, $key, null);

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
        new SnapshotScope(SourceName::OnThisDay, $key, null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'one character too long' => [str_repeat('ż', SnapshotScope::LONGEST_KEY + 1)];
    }

    #[DataProvider('datedScopes')]
    public function testAcceptsADateExactlyWhenTheSourceIsDated(SourceName $source, ?\DateTimeImmutable $date): void
    {
        // When
        $scope = new SnapshotScope($source, self::KEY, $date);

        // Then
        self::assertSame($date, $scope->date);
    }

    /**
     * @return iterable<string, array{SourceName, ?\DateTimeImmutable}>
     */
    public static function datedScopes(): iterable
    {
        yield 'picture of the day with its date' => [SourceName::Apod, new \DateTimeImmutable(self::DATE)];
        yield 'weather with its date' => [SourceName::Weather, new \DateTimeImmutable(self::DATE)];
        yield 'day in history without a date' => [SourceName::OnThisDay, null];
    }

    #[DataProvider('misdatedScopes')]
    public function testRejectsADateThatDoesNotMatchTheSource(SourceName $source, ?\DateTimeImmutable $date, string $reason): void
    {
        // Then / When
        $this->expectExceptionObject(new \InvalidArgumentException($reason));
        new SnapshotScope($source, self::KEY, $date);
    }

    /**
     * @return iterable<string, array{SourceName, ?\DateTimeImmutable, string}>
     */
    public static function misdatedScopes(): iterable
    {
        yield 'picture of the day without a date' => [SourceName::Apod, null, 'Snapshots of apod need a date.'];
        yield 'weather without a date' => [SourceName::Weather, null, 'Snapshots of weather need a date.'];
        yield 'day in history with a date' => [SourceName::OnThisDay, new \DateTimeImmutable(self::DATE), 'Snapshots of on_this_day have no date.'];
    }
}
