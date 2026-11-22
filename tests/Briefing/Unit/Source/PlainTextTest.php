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

    #[DataProvider('markup')]
    public function testDropsMarkupButKeepsTheText(string $text, string $expected): void
    {
        // When
        $plain = PlainText::fromMarkup($text);

        // Then
        self::assertSame($expected, $plain);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function markup(): iterable
    {
        yield 'plain text' => ['Caesar is stabbed.', 'Caesar is stabbed.'];
        yield 'image with a handler' => ['English actress<img src=x onerror=alert(1)>', 'English actress'];
        yield 'formatting tags' => ['<b>Caesar</b> is <i>stabbed</i>.', 'Caesar is stabbed.'];
        yield 'script' => ['Caesar<script>alert(1)</script> is stabbed.', 'Caesar alert(1) is stabbed.'];
        yield 'comment' => ['Caesar <!-- vandal --> is stabbed.', 'Caesar is stabbed.'];
        yield 'unclosed tag' => ['Caesar is stabbed <img src=x onerror=alert(1)', 'Caesar is stabbed'];
        yield 'encoded tag' => ['Caesar &lt;img src=x onerror=alert(1)&gt; is stabbed.', 'Caesar is stabbed.'];
        yield 'entities' => ['AT&amp;T &quot;Bell&quot; &#8211; Caf&eacute;', 'AT&T "Bell" – Café'];
        yield 'comparison' => ['Rated 3 < 5 and 6 > 4', 'Rated 3 < 5 and 6 > 4'];
        yield 'encoded comparison' => ['Rated 3 &lt; 5', 'Rated 3 < 5'];
        yield 'heart' => ['I <3 Rome', 'I <3 Rome'];
        yield 'ampersand' => ['Simon & Garfunkel', 'Simon & Garfunkel'];
    }
}
