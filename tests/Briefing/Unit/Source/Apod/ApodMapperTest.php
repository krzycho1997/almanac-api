<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Source\Apod;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\Exception\PayloadDefect;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\Attribution;
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
            new ApodPicture(new \DateTimeImmutable('2026-11-18 00:00:00 UTC'), self::TITLE, self::EXPLANATION, MediaType::Image, self::URL, self::HD_URL, 'Jane Doe', new Attribution('Astronomy Picture of the Day', 'https://apod.nasa.gov/', null, null, true, null)),
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
    public function testKeepsOnlyAUsableMediaAddress(string $url, ?string $expected): void
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
        yield 'plain http picture' => ['http://apod.nasa.gov/apod/image/9506/neutron.gif', null];
        yield 'upper-case secure scheme' => ['HTTPS://apod.nasa.gov/apod/image/9506/neutron.gif', null];
        yield 'empty' => ['', null];
        yield 'relative' => ['/apod/image.jpg', null];
        yield 'without a scheme' => ['//www.youtube.com/embed/abc', null];
        yield 'script' => ['javascript:alert(1)', null];
        yield 'another scheme' => ['ftp://apod.nasa.gov/a.jpg', null];
        yield 'with a space' => ['https://apod.nasa.gov/a b.jpg', null];
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
        yield 'control characters' => ["The Pillars\x0bof\x07Creation", "Towers of cold gas\x1b and dust in the Eagle Nebula.\x00", self::TITLE, 'Towers of cold gas and dust in the Eagle Nebula.'];
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
        yield 'tomorrow’s picture with a typographic apostrophe' => [self::TITLE, self::EXPLANATION.' Tomorrow’s picture: V-G-R', self::TITLE, self::EXPLANATION];
        yield 'portal universe inside a sentence' => [self::TITLE, 'The Portal Universe: a new exhibit opened.', self::TITLE, 'The Portal Universe: a new exhibit opened.'];
        yield 'greater-than sign inside an attribute' => ['<a title="1>2">M31</a>', self::EXPLANATION, 'M31', self::EXPLANATION];
        yield 'upper-case tags' => ['<B>Pillars</B> <A HREF="https://example.com">M16</A>', self::EXPLANATION, 'Pillars M16', self::EXPLANATION];
        yield 'self-closing line break' => ['Pillars<br/>', self::EXPLANATION, 'Pillars', self::EXPLANATION];
        yield 'comment over several lines' => ["Pillars<!-- of\nCreation -->", self::EXPLANATION, 'Pillars', self::EXPLANATION];
        yield 'HTML5 entity' => ['Hubble&apos;s Pillars', self::EXPLANATION, "Hubble's Pillars", self::EXPLANATION];
        yield 'comparison signs with spaces' => ['Mass < 1 and > 0', self::EXPLANATION, 'Mass < 1 and > 0', self::EXPLANATION];
        yield 'decimal without a leading zero' => [self::TITLE, 'Dust of .5 microns.', self::TITLE, 'Dust of .5 microns.'];
        yield 'space before a comma after a link' => [self::TITLE, 'Towers in <a href="https://example.com">M16</a> , dust and gas.', self::TITLE, 'Towers in M16, dust and gas.'];
    }

    public function testRejectsTheNasaSciencePlaceholder(): void
    {
        // Given
        $logo = 'https://science.nasa.gov/wp-content/themes/nasa-child/assets/images/nasa-logo@2x.png';
        $response = new ApodApiResponse(self::DATE, "NASA Science\t", 'Discover the cosmos!', 'image', $logo, $logo);

        // When
        $rejection = self::rejectionOf($response);

        // Then
        self::assertSame(['title'], $rejection->fields());
        self::assertSame(PayloadDefect::Placeholder, $rejection->defect());
    }

    #[DataProvider('textsWithoutContent')]
    public function testRejectsATitleOrExplanationWithoutText(string $title, string $explanation, string $field): void
    {
        // Given
        $response = new ApodApiResponse(self::DATE, $title, $explanation, 'image', self::URL);

        // When
        $rejection = self::rejectionOf($response);

        // Then
        self::assertSame([$field], $rejection->fields());
        self::assertSame(PayloadDefect::NoText, $rejection->defect());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function textsWithoutContent(): iterable
    {
        yield 'title of empty tags' => ['<b> </b>', self::EXPLANATION, 'title'];
        yield 'title of a non-breaking space' => ["\u{00A0}", self::EXPLANATION, 'title'];
        yield 'explanation of only the label' => [self::TITLE, '<strong>Explanation:</strong> ', 'explanation'];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsADateThatIsNotACalendarDate(string $date): void
    {
        // Given
        $response = new ApodApiResponse($date, self::TITLE, self::EXPLANATION, 'image', self::URL);

        // When
        $rejection = self::rejectionOf($response);

        // Then
        self::assertSame(['date'], $rejection->fields());
        self::assertSame(PayloadDefect::NotACalendarDate, $rejection->defect());
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

    private static function rejectionOf(ApodApiResponse $response): InvalidUpstreamPayloadException
    {
        try {
            new ApodMapper()->map($response);
        } catch (InvalidUpstreamPayloadException $exception) {
            return $exception;
        }

        self::fail('The response must be rejected.');
    }
}
