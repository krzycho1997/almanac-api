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
use Symfony\Component\Validator\Constraints\Range;

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
