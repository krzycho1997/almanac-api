<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Functional;

use App\Shared\ErrorHandler\ProblemJsonErrorRenderer;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\InteractsWithTestServices;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\Response\MockResponse;
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
    private const string LAST_ATTEMPT = '2026-11-18T12:00:00Z';
    private const string PICTURE = 'apod/passthrough-2024-03-15.json';
    private const string UNPUBLISHED_DAY = '2026-11-25';
    private const string NASA_HOST = 'api.nasa.gov';
    private const array NOT_APPLICABLE = ['status' => 'not_applicable'];
    private const array ALL_FRESH = ['apod' => self::FRESH, 'onThisDay' => self::FRESH, 'weather' => self::FRESH];
    private const array LOG_SOURCES = ['apod' => 'apod', 'onThisDay' => 'on_this_day', 'weather' => 'weather'];
    private const array EMPTY_SECTIONS = [
        'apod' => ['apod' => null],
        'onThisDay' => ['events' => [], 'births' => [], 'deaths' => []],
        'weather' => ['weather' => null],
    ];

    public function testBuildsTheBriefingFromThePictureOfTheDay(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario()->apod()->fixture(self::PICTURE));

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
                'discoveryId' => 'c05f9b6a-9262-5859-b45d-743a62e1bc6e',
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
        self::upstream()->respondWith(self::scenario()->apod()->fixture(self::PICTURE));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        self::assertResponseIsSuccessful();
        /** @var array{events: list<array{discoveryId: string, year: int, text: string, pages: list<array{title: string, url: string, thumbnail: ?string}>}>, births: list<array{discoveryId: string, year: int, name: string, description: ?string, url: string, thumbnail: ?string}>, deaths: list<array{discoveryId: string, year: int, name: string, description: ?string, url: string, thumbnail: ?string}>} $history */
        $history = self::json($this->client->getResponse()->getContent());
        self::assertSame([20, 20, 20], [\count($history['events']), \count($history['births']), \count($history['deaths'])]);
        self::assertSame([1990, 'Farzad Bazoft', 'https://en.wikipedia.org/wiki/Farzad_Bazoft'], [$history['events'][0]['year'], $history['events'][0]['pages'][0]['title'], $history['events'][0]['pages'][0]['url']]);
        self::assertSame([2005, 'Rodri Mendoza', 'Spanish footballer', 'https://en.wikipedia.org/wiki/Rodri_Mendoza'], [$history['births'][0]['year'], $history['births'][0]['name'], $history['births'][0]['description'], $history['births'][0]['url']]);
        self::assertSame([-44, 'Julius Caesar', '30ad2d96-39f2-514a-806e-58f2041d1ce6'], [$history['deaths'][19]['year'], $history['deaths'][19]['name'], $history['deaths'][19]['discoveryId']]);
        self::assertSame('e9b46a81-debf-5313-adf4-562da5ece32d', $history['events'][0]['discoveryId']);
    }

    public function testStoresEveryItemItHandsOutAnIdFor(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario()->apod()->fixture(self::PICTURE));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        self::assertResponseIsSuccessful();
        /** @var array{apod: array{discoveryId: string}, events: list<array{discoveryId: string}>, births: list<array{discoveryId: string}>, deaths: list<array{discoveryId: string}>} $briefing */
        $briefing = self::json($this->client->getResponse()->getContent());
        $ids = array_values(array_unique([$briefing['apod']['discoveryId'], ...array_column([...$briefing['events'], ...$briefing['births'], ...$briefing['deaths']], 'discoveryId')]));
        $stored = self::testService(Connection::class)->fetchFirstColumn('SELECT id::text FROM briefing_discovery WHERE id::text IN (?)', [$ids], [ArrayParameterType::STRING]);
        self::assertEqualsCanonicalizing($ids, $stored);
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
        self::upstream()->respondWith(self::scenario()->apod()->fixture(self::PICTURE));

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
        yield 'link' => [self::PICTURE, self::PICTURE_DAY, '"url":"https://assets.science.nasa.gov/'];
        yield 'accent' => ['apod/passthrough-video-2021-06-23.json', '2021-06-23', 'Grudić'];
    }

    public function testServesSeveralRequestsFromOneUpstreamScenario(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario()
            ->apod()->fixture(self::PICTURE)
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

    public function testServesAStoredPastDayAgainWithoutCallingTheUpstreams(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario()->apod()->fixture(self::PICTURE));
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);
        $first = $this->client->getResponse()->getContent();
        $served = \count(self::upstream()->served());

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        self::assertResponseIsSuccessful();
        self::assertCount($served, self::upstream()->served());
        self::assertSame($first, $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, string> $source
     * @param array<string, mixed>  $context
     */
    #[DataProvider('failedSources')]
    public function testServesTheOtherSourcesAndLogsTheFailedOneOnce(UpstreamMock $scenario, string $failed, array $source, int $requests, Level $level, string $message, array $context): void
    {
        // Given
        self::upstream()->respondWith($scenario);

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        self::assertResponseIsSuccessful();
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(array_replace(self::ALL_FRESH, [$failed => $source]), $briefing['sources']);
        self::assertSame([$failed], $briefing['missing']);
        self::assertSame(self::EMPTY_SECTIONS[$failed], array_intersect_key($briefing, self::EMPTY_SECTIONS[$failed]));
        self::assertSame(self::othersThan([$failed]), self::attributedSources($briefing));
        self::assertCount($requests, self::upstream()->served());
        $records = self::recordsOf(self::BRIEFING_CHANNEL);
        self::assertCount(1, $records);
        self::assertSame($level, $records[0]->level);
        self::assertSame($message, $records[0]->message);
        self::assertEquals(['source' => self::LOG_SOURCES[$failed], 'date' => self::PICTURE_DAY, 'outcome' => 'missing'] + $context, $records[0]->context);
    }

    /**
     * @return iterable<string, array{UpstreamMock, string, array<string, string>, int, Level, string, array<string, mixed>}>
     */
    public static function failedSources(): iterable
    {
        yield 'picture: server error twice' => [self::scenario()->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE), 'apod', self::failed('unavailable'), 6, Level::Warning, 'Source apod fetch failed: unavailable', ['kind' => 'unavailable', 'httpStatus' => 503, 'durationMs' => 2]];
        yield 'picture: stalled until max duration' => [self::scenario()->apod()->stalled(), 'apod', self::failed('unavailable'), 5, Level::Warning, 'Source apod fetch failed: unavailable', ['kind' => 'unavailable', 'errorType' => 'TransportException', 'durationMs' => 2]];
        yield 'picture: connection refused twice' => [self::scenario()->apod()->networkError()->apod()->networkError(), 'apod', self::failed('unavailable'), 6, Level::Warning, 'Source apod fetch failed: unavailable', ['kind' => 'unavailable', 'errorType' => 'TransportException', 'durationMs' => 2]];
        yield 'picture: rate limited' => [self::scenario()->apod()->status(Response::HTTP_TOO_MANY_REQUESTS)->retryAfter(3600), 'apod', self::failed('rate_limited'), 5, Level::Warning, 'Source apod fetch failed: rate_limited', ['kind' => 'rate_limited', 'httpStatus' => 429, 'retryAt' => new \DateTimeImmutable('2026-11-18 13:00:00 UTC'), 'durationMs' => 2]];
        yield 'picture: refused key' => [self::scenario()->apod()->status(Response::HTTP_FORBIDDEN), 'apod', self::failed('misconfigured'), 5, Level::Error, 'Source apod fetch failed: misconfigured', ['kind' => 'misconfigured', 'httpStatus' => 403, 'durationMs' => 2]];
        yield 'picture: rejected date' => [self::scenario()->apod()->fixture('apod/date-out-of-range.json')->status(Response::HTTP_BAD_REQUEST), 'apod', self::failed('rejected'), 5, Level::Notice, 'Source apod fetch failed: rejected', ['kind' => 'rejected', 'httpStatus' => 400, 'durationMs' => 2]];
        yield 'picture: NASA placeholder' => [self::scenario()->apod()->fixture('apod/placeholder-2026-11-18.json'), 'apod', self::failed('invalid_payload'), 5, Level::Error, 'Source apod fetch failed: invalid_payload', ['kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['title'], 'reason' => 'placeholder', 'durationMs' => 12]];
        yield 'picture: another date' => [self::scenario()->apod()->fixture('apod/passthrough-first-picture-1995-06-16.json'), 'apod', self::failed('invalid_payload'), 5, Level::Error, 'Source apod fetch failed: invalid_payload', ['kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['date'], 'reason' => 'other_date', 'durationMs' => 12]];
        yield 'picture: title without text' => [self::scenario()->apod()->json('{"date":"2024-03-15","title":"<b> </b>","explanation":"Gas","media_type":"image"}'), 'apod', self::failed('invalid_payload'), 5, Level::Error, 'Source apod fetch failed: invalid_payload', ['kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['title'], 'reason' => 'no_text', 'durationMs' => 12]];
        yield 'picture: date that is not a calendar date' => [self::scenario()->apod()->json('{"date":"2024-02-30","title":"Pillars","explanation":"Gas","media_type":"image"}'), 'apod', self::failed('invalid_payload'), 5, Level::Error, 'Source apod fetch failed: invalid_payload', ['kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['date'], 'reason' => 'invalid_value', 'durationMs' => 12]];
        yield 'picture: body of the wrong shape' => [self::scenario()->apod(), 'apod', self::failed('invalid_payload'), 5, Level::Error, 'Source apod fetch failed: invalid_payload', ['kind' => 'invalid_payload', 'httpStatus' => 200, 'errorType' => 'InvalidUpstreamPayloadException', 'fields' => ['date', 'title', 'explanation', 'mediaType'], 'reason' => 'wrong_shape', 'durationMs' => 12]];
        yield 'history: rejected' => [self::onThisDayRejected()->apod()->fixture(self::PICTURE)->archiveOn(self::PICTURE_DAY), 'onThisDay', self::failed('rejected'), 5, Level::Notice, 'Source on_this_day fetch failed: rejected', ['kind' => 'rejected', 'httpStatus' => 404, 'durationMs' => 3]];
        yield 'weather: stalled until max duration' => [UpstreamMock::create()->onThisDayFrom(self::HISTORY_DAY)->apod()->fixture(self::PICTURE)->archive()->stalled(), 'weather', self::failed('unavailable'), 5, Level::Warning, 'Source weather fetch failed: unavailable', ['kind' => 'unavailable', 'errorType' => 'TransportException', 'durationMs' => 6]];
    }

    public function testDoesNotRetryAStallThatUsedTheWholeTimeBudget(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario()->apod()->stalled()->apod()->fixture(self::PICTURE));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(array_replace(self::ALL_FRESH, ['apod' => self::failed('unavailable')]), $briefing['sources']);
        self::assertCount(1, self::requestedHosts(self::NASA_HOST));
        self::assertSame(['nasa' => 1], self::upstream()->unserved());
        self::upstream()->respondWith(UpstreamMock::create());
    }

    public function testServesTheOnlySourceThatHasData(): void
    {
        // Given
        self::upstream()->respondWith(self::onThisDayRejected()->apod()->stalled()->archiveOn(self::PICTURE_DAY));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        self::assertResponseIsSuccessful();
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(['apod' => self::failed('unavailable'), 'onThisDay' => self::failed('rejected'), 'weather' => self::FRESH], $briefing['sources']);
        self::assertSame(['apod', 'onThisDay'], $briefing['missing']);
        self::assertSame(['weather'], self::attributedSources($briefing));
        self::assertIsArray($briefing['weather']);
    }

    public function testListsADayThePictureDoesNotCoverNextToAFailedSource(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->onThisDayFrom(self::HISTORY_DAY)->forecast()->stalled());

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::UNPUBLISHED_DAY);

        // Then
        self::assertResponseIsSuccessful();
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(['apod' => self::NOT_APPLICABLE, 'onThisDay' => self::FRESH, 'weather' => self::failed('unavailable')], $briefing['sources']);
        self::assertSame(['apod', 'weather'], $briefing['missing']);
        self::assertSame(['onThisDay'], self::attributedSources($briefing));
    }

    public function testServesTheLastGoodWeatherAsStaleWhenItsRefreshFails(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->onThisDayFrom(self::HISTORY_DAY)->forecastOn(self::UNPUBLISHED_DAY));
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::UNPUBLISHED_DAY);
        $weather = self::json($this->client->getResponse()->getContent())['weather'];
        self::mockTime(new \DateTimeImmutable('2026-11-18 13:00:00', new \DateTimeZone(self::TIMEZONE)));
        self::upstream()->respondWith(UpstreamMock::create()->forecast()->status(Response::HTTP_SERVICE_UNAVAILABLE)->forecast()->status(Response::HTTP_SERVICE_UNAVAILABLE));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::UNPUBLISHED_DAY);

        // Then
        self::assertResponseIsSuccessful();
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(['apod' => self::NOT_APPLICABLE, 'onThisDay' => self::FRESH, 'weather' => ['status' => 'ok', 'fetchedAt' => '2026-11-18T12:00:00Z', 'stale' => true, 'error' => 'unavailable', 'lastAttemptAt' => '2026-11-18T13:00:00Z']], $briefing['sources']);
        self::assertSame(['apod'], $briefing['missing']);
        self::assertSame($weather, $briefing['weather']);
        $records = self::recordsOf(self::BRIEFING_CHANNEL);
        self::assertCount(1, $records);
        self::assertSame(['weather', self::UNPUBLISHED_DAY, 'unavailable', 503, 'stale'], [$records[0]->context['source'] ?? null, $records[0]->context['date'] ?? null, $records[0]->context['kind'] ?? null, $records[0]->context['httpStatus'] ?? null, $records[0]->context['outcome'] ?? null]);
    }

    public function testKeepsAFailedWeatherDuringThePauseWithItsLastAttempt(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->onThisDayFrom(self::HISTORY_DAY)->forecast()->status(Response::HTTP_BAD_REQUEST));
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::UNPUBLISHED_DAY);
        self::mockTime(new \DateTimeImmutable('2026-11-18 12:05:00', new \DateTimeZone(self::TIMEZONE)));
        self::upstream()->respondWith(UpstreamMock::create());

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::UNPUBLISHED_DAY);

        // Then
        self::assertResponseIsSuccessful();
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(['apod' => self::NOT_APPLICABLE, 'onThisDay' => self::FRESH, 'weather' => self::failed('rejected')], $briefing['sources']);
        self::assertSame([], self::upstream()->served());
        self::assertSame([], self::recordsOf(self::BRIEFING_CHANNEL));
    }

    public function testMarksThePictureNotApplicableWithoutAskingNasa(): void
    {
        // Given
        self::upstream()->respondWith(self::scenario(self::UNPUBLISHED_DAY));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::UNPUBLISHED_DAY);

        // Then
        self::assertResponseIsSuccessful();
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(['apod' => self::NOT_APPLICABLE, 'onThisDay' => self::FRESH, 'weather' => self::FRESH], $briefing['sources']);
        self::assertSame(['apod'], $briefing['missing']);
        self::assertNull($briefing['apod']);
        self::assertSame([], self::requestedHosts(self::NASA_HOST));
        self::assertSame([], self::recordsOf(self::BRIEFING_CHANNEL));
    }

    #[DataProvider('briefingsWithoutData')]
    public function testAnswersBadGatewayWhenNoSourceHasData(string $date, UpstreamMock $scenario, int $failures): void
    {
        // Given
        self::upstream()->respondWith($scenario);

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.$date);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_GATEWAY);
        self::assertResponseHeaderSame(self::CONTENT_TYPE, ProblemJsonErrorRenderer::PROBLEM_JSON);
        $problem = self::json($this->client->getResponse()->getContent());
        self::assertSame([Response::HTTP_BAD_GATEWAY, 'Bad Gateway'], [$problem['status'], $problem['detail']]);
        self::assertArrayNotHasKey('sources', $problem);
        self::assertSame([Level::Warning], self::loggedExceptionLevels());
        self::assertCount($failures, self::recordsOf(self::BRIEFING_CHANNEL));
    }

    /**
     * @return iterable<string, array{string, UpstreamMock, int}>
     */
    public static function briefingsWithoutData(): iterable
    {
        yield 'every source unavailable' => [self::PICTURE_DAY, self::onThisDayRejected()->archive()->status(Response::HTTP_BAD_REQUEST)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE), 3];
        yield 'picture not published yet, history and weather unavailable' => [self::UNPUBLISHED_DAY, self::onThisDayRejected()->forecast()->status(Response::HTTP_BAD_REQUEST), 2];
        yield 'picture and weather stalled, history rejected' => [self::PICTURE_DAY, self::onThisDayRejected()->archive()->stalled()->apod()->stalled(), 3];
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

    protected function tearDown(): void
    {
        self::assertSame([], self::upstream()->unserved(), 'Every planned upstream response must be requested.');
        parent::tearDown();
    }

    /**
     * @param list<string> $failed
     *
     * @return list<string>
     */
    private static function othersThan(array $failed): array
    {
        return array_values(array_diff(array_keys(self::ALL_FRESH), $failed));
    }

    /**
     * @param array<mixed> $briefing
     *
     * @return list<mixed>
     */
    private static function attributedSources(array $briefing): array
    {
        self::assertIsArray($briefing['attributions']);

        return array_values(array_map(static function (mixed $attribution): mixed {
            self::assertIsArray($attribution);

            return $attribution['source'];
        }, $briefing['attributions']));
    }

    /**
     * @return list<string>
     */
    private static function requestedHosts(string $host): array
    {
        return array_values(array_filter(array_map(static fn (MockResponse $response): string => (string) parse_url($response->getRequestUrl(), \PHP_URL_HOST), self::upstream()->served()), static fn (string $requested): bool => $host === $requested));
    }

    /**
     * @return array<string, string>
     */
    private static function failed(string $error): array
    {
        return ['status' => 'failed', 'error' => $error, 'lastAttemptAt' => self::LAST_ATTEMPT];
    }

    private static function onThisDayRejected(): UpstreamMock
    {
        return UpstreamMock::create()
            ->onThisDay()->status(Response::HTTP_NOT_FOUND)
            ->onThisDay()->status(Response::HTTP_NOT_FOUND)
            ->onThisDay()->status(Response::HTTP_NOT_FOUND);
    }
}
