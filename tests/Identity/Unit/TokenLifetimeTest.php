<?php

declare(strict_types=1);

namespace App\Tests\Identity\Unit;

use App\Identity\TokenLifetime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenLifetimeTest extends TestCase
{
    #[DataProvider('lifetimes')]
    public function testReadsHoursAndDays(string $text, ?int $hours): void
    {
        // When
        $lifetime = TokenLifetime::parse($text);

        // Then
        self::assertSame($hours, $lifetime?->hours);
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function lifetimes(): iterable
    {
        yield 'hours' => ['12h', 12];
        yield 'days' => ['30d', 720];
        yield 'zero' => ['0h', null];
        yield 'no unit' => ['12', null];
        yield 'weeks' => ['2w', null];
        yield 'trailing newline' => ["12h\n", null];
        yield 'a year in days' => ['365d', 8760];
        yield 'a year in hours' => ['8760h', 8760];
        yield 'one hour over a year' => ['8761h', null];
        yield 'one day over a year' => ['366d', null];
    }

    #[DataProvider('writings')]
    public function testTellsAWellFormedLifetimeFromAnyOtherText(string $text, bool $expected): void
    {
        // When
        $wellFormed = TokenLifetime::isWellFormed($text);

        // Then
        self::assertSame($expected, $wellFormed);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function writings(): iterable
    {
        yield 'hours' => ['12h', true];
        yield 'over a year, but well formed' => ['366d', true];
        yield 'no unit' => ['12', false];
    }

    public function testGivesTheLifetimeAsAnInterval(): void
    {
        // When
        $interval = TokenLifetime::parse('2d')?->interval();

        // Then
        self::assertSame(48, $interval?->h);
    }
}
