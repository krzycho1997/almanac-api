<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source\Apod;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\MediaType;
use App\Briefing\Source\Apod\ApodApiResponse;
use App\Briefing\Source\Apod\ApodMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApodMapperTest extends TestCase
{
    private const string DATE = '2026-11-18';
    private const string TITLE = 'The Pillars of Creation';
    private const string EXPLANATION = 'Towers of cold gas and dust in the Eagle Nebula.';
    private const string URL = 'https://apod.nasa.gov/apod/image/2611/pillars_1024.jpg';
    private const string HD_URL = 'https://apod.nasa.gov/apod/image/2611/pillars.jpg';

    public function testMapsAPictureToTheModel(): void
    {
        // Given
        $response = new ApodApiResponse(self::DATE, self::TITLE, self::EXPLANATION, 'image', self::URL, self::HD_URL, 'Jane Doe');

        // When
        $picture = new ApodMapper()->map($response);

        // Then
        self::assertEquals(
            new ApodPicture(new \DateTimeImmutable('2026-11-18 00:00:00 UTC'), self::TITLE, self::EXPLANATION, MediaType::Image, self::URL, self::HD_URL, 'Jane Doe'),
            $picture,
        );
    }

    #[DataProvider('mediaTypes')]
    public function testMapsTheMediaType(string $mediaType, MediaType $expected): void
    {
        // Given
        $response = new ApodApiResponse(self::DATE, self::TITLE, self::EXPLANATION, $mediaType, self::URL);

        // When
        $picture = new ApodMapper()->map($response);

        // Then
        self::assertSame($expected, $picture->mediaType);
    }

    /**
     * @return iterable<string, array{string, MediaType}>
     */
    public static function mediaTypes(): iterable
    {
        yield 'image' => ['image', MediaType::Image];
        yield 'video' => ['video', MediaType::Video];
        yield 'other' => ['other', MediaType::Other];
        yield 'unknown to us' => ['interactive', MediaType::Other];
    }

    public function testKeepsAVideoWithoutAHighResolutionUrl(): void
    {
        // Given
        $response = new ApodApiResponse(self::DATE, self::TITLE, self::EXPLANATION, 'video', 'https://www.youtube.com/embed/abc');

        // When
        $picture = new ApodMapper()->map($response);

        // Then
        self::assertNull($picture->hdUrl);
        self::assertNull($picture->credit);
    }

    #[DataProvider('mediaUrls')]
    public function testDropsTheGenericThumbnailNasaGivesInsteadOfTheMedia(string $url, ?string $expected): void
    {
        // Given
        $response = new ApodApiResponse(self::DATE, self::TITLE, self::EXPLANATION, 'video', $url, $url);

        // When
        $picture = new ApodMapper()->map($response);

        // Then
        self::assertSame($expected, $picture->url);
        self::assertSame($expected, $picture->hdUrl);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function mediaUrls(): iterable
    {
        yield 'generic thumbnail' => ['https://assets.science.nasa.gov/dynamicimage/assets/science/astro/programs/cosmic-origins/images/misc/news-thumbnail.png?w=594&h=516&fit=clip', null];
        yield 'picture' => [self::HD_URL, self::HD_URL];
        yield 'thumbnail named only in the query' => ['https://www.youtube.com/embed/abc?poster=/images/misc/news-thumbnail.png', 'https://www.youtube.com/embed/abc?poster=/images/misc/news-thumbnail.png'];
    }

    #[DataProvider('credits')]
    public function testNormalisesTheCredit(?string $copyright, ?string $expected): void
    {
        // Given
        $response = new ApodApiResponse(self::DATE, self::TITLE, self::EXPLANATION, 'image', self::URL, copyright: $copyright);

        // When
        $picture = new ApodMapper()->map($response);

        // Then
        self::assertSame($expected, $picture->credit);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function credits(): iterable
    {
        yield 'single author' => ['Jane Doe', 'Jane Doe'];
        yield 'broken over lines' => ["\nJane Doe &\nJohn Roe\n", 'Jane Doe & John Roe'];
        yield 'blank' => ["  \n ", null];
        yield 'public domain' => [null, null];
        yield 'empty' => ['', null];
        yield 'HTML credit line' => ['<b> Image Credit &amp; <a href="https://apod.nasa.gov/apod/lib/about_apod.html">Copyright</a>: </b> <a href="https://example.com">Dave Doctor</a>', 'Image Credit & Copyright: Dave Doctor'];
    }

    #[DataProvider('texts')]
    public function testTurnsTitleAndExplanationIntoPlainText(string $title, string $explanation, string $expectedTitle, string $expectedExplanation): void
    {
        // Given
        $response = new ApodApiResponse(self::DATE, $title, $explanation, 'image', self::URL);

        // When
        $picture = new ApodMapper()->map($response);

        // Then
        self::assertSame($expectedTitle, $picture->title);
        self::assertSame($expectedExplanation, $picture->explanation);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function texts(): iterable
    {
        yield 'plain text' => [self::TITLE, self::EXPLANATION, self::TITLE, self::EXPLANATION];
        yield 'trailing tabs' => [" The Pillars of Creation\n", "Towers of cold gas and dust in the Eagle Nebula.\t\t\t", self::TITLE, self::EXPLANATION];
        yield 'HTML with the explanation label' => [self::TITLE, '<strong>Explanation:</strong> Towers of cold gas and dust in the <a href="https://example.com">Eagle&nbsp;Nebula</a>.', self::TITLE, self::EXPLANATION];
        yield 'escaped characters' => ['Pillars &amp; Columns', 'Gas &quot;towers&quot;', 'Pillars & Columns', 'Gas "towers"'];
        yield 'plain text with a less-than sign' => ['Gas at T<10K', 'Gas at T<10K is cold. It stays dark.', 'Gas at T<10K', 'Gas at T<10K is cold. It stays dark.'];
        yield 'escaped tag' => ['The &lt;b&gt; tag', self::EXPLANATION, 'The <b> tag', self::EXPLANATION];
        yield 'comment' => ['Pillars<!-- of Creation -->', self::EXPLANATION, 'Pillars', self::EXPLANATION];
        yield 'space before punctuation after a link' => [self::TITLE, 'Towers of cold gas and dust in the <a href="https://example.com">Eagle Nebula</a> .', self::TITLE, self::EXPLANATION];
        yield 'explanation label inside the text' => [self::TITLE, 'Towers of gas. Explanation: none needed.', self::TITLE, 'Towers of gas. Explanation: none needed.'];
        yield 'link to a random picture after the text' => [self::TITLE, self::EXPLANATION.'<br /><br /><b> Portal Universe: </b> <a href="https://apod.nasa.gov/apod/random_apod.html">Random APOD Generator</a> <br />', self::TITLE, self::EXPLANATION];
        yield "tomorrow's picture after the text" => [self::TITLE, self::EXPLANATION."Tomorrow's picture: V-G-R\t\t\t", self::TITLE, self::EXPLANATION];
    }

    public function testRejectsTheNasaSciencePlaceholder(): void
    {
        // Given
        $logo = 'https://science.nasa.gov/wp-content/themes/nasa-child/assets/images/nasa-logo@2x.png';
        $response = new ApodApiResponse(self::DATE, "NASA Science\t", 'Discover the cosmos!', 'image', $logo, $logo);

        // Then / When
        $this->expectException(InvalidUpstreamPayloadException::class);
        new ApodMapper()->map($response);
    }

    #[DataProvider('textsWithoutContent')]
    public function testRejectsATitleOrExplanationWithoutText(string $title, string $explanation): void
    {
        // Given
        $response = new ApodApiResponse(self::DATE, $title, $explanation, 'image', self::URL);

        // Then / When
        $this->expectException(InvalidUpstreamPayloadException::class);
        new ApodMapper()->map($response);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textsWithoutContent(): iterable
    {
        yield 'title of empty tags' => ['<b> </b>', self::EXPLANATION];
        yield 'title of a non-breaking space' => ["\u{00A0}", self::EXPLANATION];
        yield 'explanation of only the label' => [self::TITLE, '<strong>Explanation:</strong> '];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsADateThatIsNotACalendarDate(string $date): void
    {
        // Given
        $response = new ApodApiResponse($date, self::TITLE, self::EXPLANATION, 'image', self::URL);

        // Then / When
        $this->expectException(InvalidUpstreamPayloadException::class);
        new ApodMapper()->map($response);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'day after the end of the month' => ['2026-11-31'];
        yield 'another format' => ['18/11/2026'];
        yield 'with a time' => ['2026-11-18T00:00:00'];
        yield 'empty' => [''];
    }
}
