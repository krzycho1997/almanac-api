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

    public function testBuildsTheBriefingFromThePictureOfTheDay(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture('apod/passthrough-2024-03-15.json'));

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // Then
        self::assertResponseIsSuccessful();
        self::assertSame('https://api.nasa.gov/planetary/apod?date=2024-03-15', self::upstream()->served()[0]->getRequestUrl());
        $briefing = self::json($this->client->getResponse()->getContent());
        self::assertSame(self::PICTURE_DAY, $briefing['date']);
        self::assertSame(['latitude' => 48.86, 'longitude' => 2.35, 'name' => 'Paris', 'isDefault' => true], $briefing['location']);
        self::assertSame(['apod' => ['status' => 'ok', 'fetchedAt' => '2026-11-18T12:00:00Z', 'stale' => false]], $briefing['sources']);
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
    }

    public function testBuildsTheBriefingForAPictureWithoutMediaOrCredit(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture('apod/passthrough-first-picture-1995-06-16.json'));

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
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture('apod/passthrough-2024-03-15.json'));

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
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture($fixture));

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
        self::upstream()->respondWith(UpstreamMock::create()
            ->apod()->fixture('apod/passthrough-2024-03-15.json')
            ->apod()->fixture('apod/passthrough-first-picture-1995-06-16.json'));
        $this->client->request(Request::METHOD_GET, self::BRIEFING.self::PICTURE_DAY);

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING.'1995-06-16');

        // Then
        self::assertResponseIsSuccessful();
        self::assertCount(2, self::upstream()->served());
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
        yield 'picture unavailable' => [self::PICTURE_DAY, UpstreamMock::create()->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)];
        yield 'day the picture does not cover yet' => ['2026-11-25', UpstreamMock::create()];
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
}
