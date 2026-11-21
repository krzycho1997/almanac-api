<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source\OnThisDay;

use App\Briefing\Source\OnThisDay\ComparableText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComparableTextTest extends TestCase
{
    #[DataProvider('texts')]
    public function testComparesTextWithoutAccentsOrCase(string $text, string $expected): void
    {
        // When
        $comparable = ComparableText::of($text);

        // Then
        self::assertSame($expected, $comparable);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'accents' => ['Kurt Gödel', 'kurt godel'];
        yield 'decomposed accents' => ["Kurt Go\u{0308}del", 'kurt godel'];
        yield 'capitals' => ['ÉMILE ZOLA', 'emile zola'];
        yield 'plain' => ['julius caesar', 'julius caesar'];
    }
}
