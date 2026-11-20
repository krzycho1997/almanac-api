<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Recording;

use App\Tests\Support\Briefing\Recording\FixtureFiles;
use App\Tests\Support\Briefing\Recording\Trim;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrimTest extends TestCase
{
    public function testTurnsTheWordPressAnswerIntoWhatTheApodForwarderServes(): void
    {
        // Given
        $raw = self::json('{"date":"2024-03-15","url":"https://apod.test/small.jpg","hdurl":"https://apod.test/big.jpg","basic_html":"<p>x</p>"}');

        // When
        $trimmed = Trim::ApodPassthrough->apply($raw);

        // Then
        self::assertSame('{"date":"2024-03-15","url":"https://apod.test/big.jpg","hdurl":"https://apod.test/big.jpg","service_version":"v1"}', self::compact($trimmed));
    }

    public function testKeepsTheUrlOfAPictureWithoutAnHdVersion(): void
    {
        // When
        $trimmed = Trim::ApodPassthrough->apply(self::json('{"url":"https://apod.test/video"}'));

        // Then
        self::assertSame('{"url":"https://apod.test/video","service_version":"v1"}', self::compact($trimmed));
    }

    public function testKeepsOnlyThePageFieldsInAFixedOrder(): void
    {
        // Given
        $raw = self::json('{"births":[{"text":"A","pages":[{"wikibase_item":"Q1","title":"A_B","thumbnail":{"source":"https://img.test/a.jpg","width":1,"height":2,"mime":"image/jpeg"},"extract":"long","content_urls":{"desktop":{"page":"https://w.test/A","edit":"e"},"mobile":{"page":"m"}},"type":"standard"}],"year":1900}]}');

        // When
        $trimmed = Trim::OnThisDayReadFields->apply($raw);

        // Then
        self::assertSame('{"births":[{"text":"A","pages":[{"type":"standard","title":"A_B","thumbnail":{"source":"https://img.test/a.jpg","width":1,"height":2},"content_urls":{"desktop":{"page":"https://w.test/A"}},"wikibase_item":"Q1"}],"year":1900}]}', self::compact($trimmed));
    }

    public function testKeepsEmptyObjectsAsObjects(): void
    {
        // When
        $trimmed = Trim::None->apply(self::json('{"daily_units":{},"daily":[]}'));

        // Then
        self::assertSame('{"daily_units":{},"daily":[]}', self::compact($trimmed));
    }

    #[DataProvider('unexpectedShapes')]
    public function testRefusesAShapeItCannotTrimFaithfully(Trim $trim, string $raw): void
    {
        // Then / When
        $this->expectException(\UnexpectedValueException::class);
        $trim->apply(self::json($raw));
    }

    /**
     * @return iterable<string, array{Trim, string}>
     */
    public static function unexpectedShapes(): iterable
    {
        yield 'APOD without any picture address' => [Trim::ApodPassthrough, '{"date":"2024-03-15"}'];
        yield 'APOD answer that is a list' => [Trim::ApodPassthrough, '[]'];
        yield 'On This Day list we do not read' => [Trim::OnThisDayReadFields, '{"holidays":[]}'];
        yield 'On This Day list that is not a list' => [Trim::OnThisDayReadFields, '{"births":"none"}'];
        yield 'page without a desktop address' => [Trim::OnThisDayReadFields, '{"births":[{"pages":[{"content_urls":{"mobile":{"page":"m"}}}]}]}'];
        yield 'desktop address without a page' => [Trim::OnThisDayReadFields, '{"births":[{"pages":[{"content_urls":{"desktop":{}}}]}]}'];
        yield 'thumbnail that is not an object' => [Trim::OnThisDayReadFields, '{"births":[{"pages":[{"thumbnail":"x"}]}]}'];
    }

    private static function json(string $json): mixed
    {
        return json_decode($json, false, flags: \JSON_THROW_ON_ERROR);
    }

    private static function compact(mixed $value): string
    {
        return json_encode(json_decode(FixtureFiles::encode($value)), \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
