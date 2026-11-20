<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Functional;

use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\InteractsWithTestServices;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Date;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Type;

final class BriefingControllerTest extends FunctionalTestCase
{
    use InteractsWithTestServices;

    private const string BRIEFING = '/api/briefings/';
    private const string PICTURE_DAY = '2024-03-15';
    private const string CONTENT_TYPE = 'Content-Type';
    private const string URN = 'urn:uuid:';
    private const string BRIEFING_CHANNEL = 'briefing';
    private const string HISTORY_DAY = '03-15';
    private const string FIRST_FORECAST_DAY = '2026-11-13';
    private const array FRESH = ['status' => 'ok', 'fetchedAt' => '2026-11-18T12:00:00Z', 'stale' => false];

    public function testBuildsTheBriefingFromThePictureOfTheDay(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario()->apod()->fixture('apod/passthrough-2024-03-15.json'));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        self::assertResponseIsSuccessful();
        self::assertSame('https://api.nasa.gov/planetary/apod?date=2024-03-15', self::upstream()->served()[0]->getRequestUrl());
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(self::PICTURE_DAY, $briefing['date']);
        self::assertSame(['latitude' => 48.86, 'longitude' => 2.35, 'name' => 'Paris', 'isDefault' => true], $briefing['location']);
        self::assertSame(['apod' => self::FRESH, 'onThisDay' => self::FRESH, 'weather' => self::FRESH], $briefing['sources']);
        self::assertSame([], $briefing['missing']);
        self::assertSame(
            [
                'title' => 'Portrait of NGC 1055',
                'explanation' => self::apodExplanation($briefing),
                'mediaType' => 'image',
                'url' => 'https://assets.science.nasa.gov/dynamicimage/assets/science/cds/apod/apod/2024/march/Image133k_n1055.jpg?w=2730&h=2298&fit=clip',
                'hdUrl' => 'https://assets.science.nasa.gov/dynamicimage/assets/science/cds/apod/apod/2024/march/Image133k_n1055.jpg?w=2730&h=2298&fit=clip',
                'credit' => 'Image Credit & Copyright: Dave Doctor',
            ],
            $briefing['apod'],
        );
        self::assertStringStartsWith('Big, beautiful spiral galaxy NGC 1055', self::apodExplanation($briefing));
        self::assertSame(
            [
                'kind' => 'final',
                'timezone' => 'Europe/Paris',
                'temperatureMin' => 11.1,
                'temperatureMax' => 16.2,
                'precipitationSum' => 9.7,
                'windSpeedMax' => 28.9,
                'weatherCode' => 61,
                'description' => 'Slight rain',
                'sunrise' => '2024-03-15T07:03:30+01:00',
                'sunset' => '2024-03-15T18:56:37+01:00',
                'daylightDuration' => 42786,
            ],
            $briefing['weather'],
        );
        self::assertSame(
            [
                ['source' => 'apod', 'name' => 'Astronomy Picture of the Day', 'url' => 'https://apod.nasa.gov/', 'license' => null, 'licenseUrl' => null, 'modified' => true, 'notice' => null],
                ['source' => 'onThisDay', 'name' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/', 'license' => 'CC BY-SA 4.0', 'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'modified' => true, 'notice' => null],
                ['source' => 'weather', 'name' => 'Open-Meteo', 'url' => 'https://open-meteo.com/', 'license' => 'CC BY 4.0', 'licenseUrl' => 'https://creativecommons.org/licenses/by/4.0/', 'modified' => true, 'notice' => 'Generated using Copernicus Climate Change Service information'],
            ],
            $briefing['attributions'],
        );
    }

    public function testBuildsTheBriefingWithThisDayInHistory(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario()->apod()->fixture('apod/passthrough-2024-03-15.json'));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        self::assertResponseIsSuccessful();
        /** @var array{events: list<array{year: int, text: string, pages: list<array{title: string, url: string, thumbnail: ?string}>}>, births: list<array{year: int, name: string, description: ?string, url: string, thumbnail: ?string}>, deaths: list<array{year: int, name: string, description: ?string, url: string, thumbnail: ?string}>} $history */
        $history = self::json($this->client->getResponse()->getContent());
        self::assertSame([20, 20, 20], [\count($history['events']), \count($history['births']), \count($history['deaths'])]);
        self::assertSame([1990, 'Farzad Bazoft', 'https://en.wikipedia.org/wiki/Farzad_Bazoft'], [$history['events'][0]['year'], $history['events'][0]['pages'][0]['title'], $history['events'][0]['pages'][0]['url']]);
        self::assertSame([2005, 'Rodri Mendoza', 'Spanish footballer', 'https://en.wikipedia.org/wiki/Rodri_Mendoza'], [$history['births'][0]['year'], $history['births'][0]['name'], $history['births'][0]['description'], $history['births'][0]['url']]);
        self::assertSame([-44, 'Julius Caesar'], [$history['deaths'][19]['year'], $history['deaths'][19]['name']]);
    }

    public function testBuildsTheBriefingForAPictureWithoutMediaOrCredit(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario('1995-06-16')->apod()->fixture('apod/passthrough-first-picture-1995-06-16.json'));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.'1995-06-16');

        // Then
        self::assertResponseIsSuccessful();
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertIsArray($briefing['apod']);
        self::assertSame(['Neutron Star Earth', null, null, null], [$briefing['apod']['title'], $briefing['apod']['url'], $briefing['apod']['hdUrl'], $briefing['apod']['credit']]);
    }

    #[DataProvider('coordinates')]
    public function testBuildsTheBriefingForTheGivenPlace(string $query, float $latitude, float $longitude): void
    {
        // Given
        self::upstream()->respondWith(self::scenario()->apod()->fixture('apod/passthrough-2024-03-15.json'));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY.$query);

        // Then
        self::assertResponseIsSuccessful();
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(['latitude' => $latitude, 'longitude' => $longitude, 'name' => null, 'isDefault' => false], $briefing['location']);
    }

    /**
     * @return iterable<string, array{string, float, float}>
     */
    public static function coordinates(): iterable
    {
        yield 'Warsaw' => ['?lat=52.23&lon=21.01', 52.23, 21.01];
        yield 'rounded to two places' => ['?lat=52.2297&lon=21.0122', 52.23, 21.01];
        yield 'edges of the globe' => ['?lat=-90&lon=180', -90.0, 180.0];
        yield 'the default place given explicitly' => ['?lat=48.8566&lon=2.3522', 48.86, 2.35];
    }

    /**
     * @param list<string> $paths
     */
    #[DataProvider('invalidCoordinates')]
    public function testRejectsInvalidCoordinatesWithTheirViolations(string $query, array $paths): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY.$query);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame($paths, self::violatedPaths(self::json($this->client->getResponse()->getContent())));
        self::assertSame([], self::upstream()->served());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function invalidCoordinates(): iterable
    {
        yield 'latitude without longitude' => ['?lat=52.23', ['lon']];
        yield 'longitude without latitude' => ['?lon=21.01', ['lat']];
        yield 'latitude beyond a pole' => ['?lat=90.01&lon=21.01', ['lat']];
        yield 'longitude beyond the date line' => ['?lat=52.23&lon=-180.01', ['lon']];
        yield 'empty values' => ['?lat=&lon=', ['lat', 'lon']];
        yield 'not a number' => ['?lat=abc&lon=21.01', ['lat']];
        yield 'not a number literal' => ['?lat=NaN&lon=INF', ['lat', 'lon']];
        yield 'exponent' => ['?lat=1e1&lon=21.01', ['lat']];
        yield 'leading space' => ['?lat=%2052.23&lon=21.01', ['lat']];
        yield 'plus sign' => ['?lat=%2B52.23&lon=21.01', ['lat']];
        yield 'list instead of a value' => ['?lat[]=52.23&lon=21.01', ['lat']];
        yield 'trailing new line' => ['?lat=52.23%0A&lon=21.01', ['lat']];
        yield 'point without decimals' => ['?lat=52.&lon=21.01', ['lat']];
        yield 'leading zeros' => ['?lat=0052.23&lon=21.01', ['lat']];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('coordinateErrorCodes')]
    public function testNamesWhatIsWrongWithACoordinate(string $query, array $codes): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY.$query);

        // Then
        self::assertSame($codes, self::violationTypes(self::json($this->client->getResponse()->getContent())));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function coordinateErrorCodes(): iterable
    {
        yield 'blank' => ['?lat=&lon=21.01', [self::URN.NotBlank::IS_BLANK_ERROR]];
        yield 'list' => ['?lat[]=52.23&lon=21.01', [self::URN.Type::INVALID_TYPE_ERROR]];
        yield 'not plain decimal' => ['?lat=1e1&lon=21.01', [self::URN.Regex::REGEX_FAILED_ERROR]];
        yield 'out of range' => ['?lat=90.01&lon=21.01', [self::URN.Range::NOT_IN_RANGE_ERROR]];
        yield 'without its pair' => ['?lat=52.23', [self::URN.NotNull::IS_NULL_ERROR]];
    }

    public function testLogsInvalidCoordinatesAsAClientError(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY.'?lat=91&lon=0');

        // Then
        self::assertSame([Level::Notice], self::loggedExceptionLevels());
        self::assertSame([], self::levelsFrom(Level::Warning));
    }

    #[DataProvider('unescapedText')]
    public function testSendsLinksAndAccentsWithoutEscaping(string $fixture, string $date, string $text): void
    {
        // Given
        self::upstream()->respondWith(self::scenario($date)->apod()->fixture($fixture));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.$date);

        // Then
        self::assertStringContainsString($text, (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unescapedText(): iterable
    {
        yield 'link' => ['apod/passthrough-2024-03-15.json', self::PICTURE_DAY, '"url":"https://assets.science.nasa.gov/'];
        yield 'accent' => ['apod/passthrough-video-2021-06-23.json', '2021-06-23', 'Grudić'];
    }

    public function testServesSeveralRequestsFromOneUpstreamScenario(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario()
            ->apod()->fixture('apod/passthrough-2024-03-15.json')
            ->onThisDayFrom(self::HISTORY_DAY)
            ->archiveOn('1995-06-16')
            ->apod()->fixture('apod/passthrough-first-picture-1995-06-16.json'));
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.'1995-06-16');

        // Then
        self::assertResponseIsSuccessful();
        self::assertCount(10, self::upstream()->served());
    }

    #[DataProvider('briefingsWithoutData')]
    public function testAnswersBadGatewayWhenNoSourceHasData(string $date, UpstreamMock $scenario): void
    {
        // Given
        self::upstream()->respondWith($scenario);

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.$date);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        self::assertResponseHeaderSame(self::CONTENT_TYPE, ProblemJsonErrorRenderer::PROBLEM_JSON);
        self::assertSame([Level::Warning], self::loggedExceptionLevels());
    }

    /**
     * @return iterable<string, array{string, UpstreamMock}>
     */
    public static function briefingsWithoutData(): iterable
    {
        yield 'every source unavailable' => [self::PICTURE_DAY, self::onThisDayRejected()->archive()->status(Response::HTTP_BAD_REQUEST)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)];
        yield 'picture not published yet, history and weather unavailable' => ['2026-11-25', self::onThisDayRejected()->forecast()->status(Response::HTTP_BAD_REQUEST)];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('failedPictures')]
    public function testLogsTheFailedSourceOnceAtItsKindsLevel(UpstreamMock $scenario, Level $level, string $message, array $context): void
    {
        // Given
        self::upstream()->respondWith($scenario);

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        $records = self::recordsOf(self::BRIEFING_CHANNEL);
        self::assertCount(1, $records);
        self::assertSame($level, $records[0]->level);
        self::assertSame($message, $records[0]->message);
        self::assertSame(['source' => 'apod', 'date' => self::PICTURE_DAY] + $context, $records[0]->context);
    }

    /**
     * @return iterable<string, array{UpstreamMock, Level, string, array<string, mixed>}>
     */
    public static function failedPictures(): iterable
    {
        yield 'server error twice' => [self::scenario()->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE), Level::Warning, 'Source apod fetch failed: unavailable', ['kind' => 'unavailable', 'httpStatus' => 503]];
        yield 'refused key' => [self::scenario()->apod()->status(Response::HTTP_FORBIDDEN), Level::Error, 'Source apod fetch failed: misconfigured', ['kind' => 'misconfigured', 'httpStatus' => 403]];
        yield 'rejected date' => [self::scenario()->apod()->fixture('apod/date-out-of-range.json')->status(Response::HTTP_BAD_REQUEST), Level::Notice, 'Source apod fetch failed: rejected', ['kind' => 'rejected', 'httpStatus' => 400]];
        yield 'NASA placeholder' => [self::scenario()->apod()->fixture('apod/placeholder-2026-11-18.json'), Level::Error, 'Source apod fetch failed: invalid_payload', ['kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['title'], 'reason' => 'placeholder']];
        yield 'picture of another date' => [self::scenario()->apod()->fixture('apod/passthrough-first-picture-1995-06-16.json'), Level::Error, 'Source apod fetch failed: invalid_payload', ['kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['date'], 'reason' => 'other_date']];
        yield 'title without text' => [self::scenario()->apod()->json('{"date":"2024-03-15","title":"<b> </b>","explanation":"Gas","media_type":"image"}'), Level::Error, 'Source apod fetch failed: invalid_payload', ['kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['title'], 'reason' => 'no_text']];
        yield 'date that is not a calendar date' => [self::scenario()->apod()->json('{"date":"2024-02-30","title":"Pillars","explanation":"Gas","media_type":"image"}'), Level::Error, 'Source apod fetch failed: invalid_payload', ['kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['date'], 'reason' => 'invalid_value']];
        yield 'body of the wrong shape' => [self::scenario()->apod(), Level::Error, 'Source apod fetch failed: invalid_payload', ['kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['date', 'title', 'explanation', 'mediaType'], 'reason' => 'wrong_shape']];
        yield 'network error twice' => [self::scenario()->apod()->networkError()->apod()->networkError(), Level::Warning, 'Source apod fetch failed: unavailable', ['kind' => 'unavailable', 'errorType' => 'TransportException']];
    }

    public function testLogsNothingOnTheBriefingChannelForADayThePictureDoesNotCover(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario('2026-11-25'));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.'2026-11-25');

        // Then
        self::assertSame([], self::recordsOf(self::BRIEFING_CHANNEL));
    }

    #[DataProvider('invalidDates')]
    public function testRejectsAnInvalidDateWithItsViolation(string $date, int $status, string $type): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.$date);

        // Then
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame(self::CONTENT_TYPE, ProblemJsonErrorRenderer::PROBLEM_JSON);
        $problem = self::json($this->client->getResponse()->getContent());
        self::assertSame('https://symfony.com/errors/validation', $problem['type']);
        self::assertSame($status, $problem['status']);
        $violation = self::firstViolation($problem);
        self::assertSame('date', $violation['propertyPath']);
        self::assertSame($type, $violation['type']);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'not a calendar date' => ['2026-02-30', Response::HTTP_BAD_REQUEST, self::URN.Date::INVALID_DATE_ERROR];
        yield 'not in Y-m-d' => ['20261118', Response::HTTP_BAD_REQUEST, self::URN.Date::INVALID_FORMAT_ERROR];
        yield 'before the first picture' => ['1995-06-15', Response::HTTP_UNPROCESSABLE_ENTITY, self::URN.Range::NOT_IN_RANGE_ERROR];
        yield 'more than fifteen days ahead' => ['2026-12-04', Response::HTTP_UNPROCESSABLE_ENTITY, self::URN.Range::NOT_IN_RANGE_ERROR];
    }

    #[DataProvider('clientErrorDates')]
    public function testLogsAnInvalidDateAsAClientError(string $date): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.$date);

        // Then
        self::assertSame([Level::Notice], self::loggedExceptionLevels());
        self::assertSame([], self::levelsFrom(Level::Warning));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clientErrorDates(): iterable
    {
        yield 'not a calendar date' => ['2026-02-30'];
        yield 'outside the window' => ['1995-06-15'];
    }

    private static function upstream(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }

    /**
     * @return array<mixed>
     */
    private static function json(string|false $content): array
    {
        $decoded = json_decode((string) $content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $problem
     *
     * @return list<mixed>
     */
    private static function violatedPaths(array $problem): array
    {
        return self::violationField($problem, 'propertyPath');
    }

    /**
     * @param array<mixed> $problem
     *
     * @return list<mixed>
     */
    private static function violationTypes(array $problem): array
    {
        return self::violationField($problem, 'type');
    }

    /**
     * @param array<mixed> $problem
     *
     * @return list<mixed>
     */
    private static function violationField(array $problem, string $field): array
    {
        self::assertIsArray($problem['violations']);

        return array_values(array_map(static function (mixed $violation) use ($field): mixed {
            self::assertIsArray($violation);

            return $violation[$field];
        }, $problem['violations']));
    }

    /**
     * @param array<mixed> $problem
     *
     * @return array<mixed>
     */
    private static function firstViolation(array $problem): array
    {
        self::assertIsArray($problem['violations']);
        self::assertIsArray($problem['violations'][0]);

        return $problem['violations'][0];
    }

    /**
     * @param array<mixed> $briefing
     */
    private static function apodExplanation(array $briefing): string
    {
        self::assertIsArray($briefing['apod']);
        self::assertIsString($briefing['apod']['explanation']);

        return $briefing['apod']['explanation'];
    }

    private static function scenario(string $day = self::PICTURE_DAY): UpstreamMock
    {
        $scenario = UpstreamMock::create()->onThisDayFrom(self::HISTORY_DAY);

        if ($day < self::FIRST_FORECAST_DAY) {
            return $scenario->archiveOn($day);
        }

        return $scenario->forecastOn($day);
    }

    private static function onThisDayRejected(): UpstreamMock
    {
        return UpstreamMock::create()
            ->onThisDay()->status(Response::HTTP_NOT_FOUND)
            ->onThisDay()->status(Response::HTTP_NOT_FOUND)
            ->onThisDay()->status(Response::HTTP_NOT_FOUND);
    }
}
