<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source;

use App\Briefing\Source\PlainText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlainTextTest extends TestCase
{
    #[DataProvider('texts')]
    public function testLeavesOneSpaceBetweenWords(string $text, string $expected): void
    {
        // When
        $tidy = PlainText::tidy($text);

        // Then
        self::assertSame($expected, $tidy);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'already tidy' => ['Caesar is stabbed.', 'Caesar is stabbed.'];
        yield 'runs of whitespace' => ["  Caesar\t is\n\nstabbed. ", 'Caesar is stabbed.'];
        yield 'space before punctuation' => ['Caesar , the dictator , is stabbed .', 'Caesar, the dictator, is stabbed.'];
        yield 'null character' => ["Caesar\0is stabbed.\0", 'Caesar is stabbed.'];
        yield 'other control characters' => ["Caesar\x07is\x1bstabbed.\x7f", 'Caesar is stabbed.'];
        yield 'only control characters' => ["\0\x07", ''];
    }
}
