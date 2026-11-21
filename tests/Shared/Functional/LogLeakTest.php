<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Identity\Entity\AccessToken;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\InteractsWithTestServices;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LogLeakTest extends FunctionalTestCase
{
    use InteractsWithTestServices;

    private const string ADDRESS = 'grace.hopper@example.com';
    private const string LATITUDE = '52.2297';
    private const string LONGITUDE = '21.0122';
    private const string ROUNDED_LATITUDE = '52.23';
    private const string ROUNDED_LONGITUDE = '21.01';
    private const string DAY = '2024-03-15';
    private const string MONTH_DAY = '03-15';
    private const string BRIEFING = '/api/briefings/'.self::DAY.'?lat='.self::LATITUDE.'&lon='.self::LONGITUDE;
    private const string EMAIL_ARGUMENT = 'email';
    private const string LINE_BREAK = "\n";
    private const string MISSING_PAGE = '/api/briefing';
    private const string REFERER = 'HTTP_REFERER';
    private const string ORIGIN = 'https://almanac.example';
    private const string BOOKMARKS = '/api/bookmarks';
    private const string UNKNOWN_DISCOVERY = '{"discoveryId": "2ed6657d-e927-568b-95e1-2665a8aea6a2"}';
    private const string COMMAND = 'almanac:user:create';
    private const string TOKEN = '/\b[0-9a-f]{64}\b/';
    private const string AUTHORIZATION = 'HTTP_AUTHORIZATION';
    private const string BEARER = 'Bearer ';
    private const string WRONG_TOKEN = 'Bearer 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const string CONTENT_TYPE = 'CONTENT_TYPE';
    private const string JSON = 'application/json';
    private const array PROD_LEVELS = [
        'event' => null,
        'http_client' => null,
        'request' => Level::Notice,
        'doctrine' => Level::Warning,
    ];

    public function testKeepsCoordinatesOutOfTheLogsOfABriefingWhoseSourceFails(): void
    {
        // Given
        self::testService(UpstreamMockResponseFactory::class)->respondWith(self::pictureUnavailableTwice());

        // When
        $this->client->request(Request::METHOD_GET, self::BRIEFING);

        // Then
        self::assertResponseIsSuccessful();
        self::assertNothingWrittenContains(self::LATITUDE, self::LONGITUDE, self::ROUNDED_LATITUDE, self::ROUNDED_LONGITUDE);
    }

    public function testKeepsCoordinatesOutOfTheLogsOfAMissedPageReferredByABriefing(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::MISSING_PAGE, server: [self::REFERER => self::ORIGIN.self::BRIEFING]);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertNothingWrittenContains(self::LATITUDE, self::LONGITUDE, self::ROUNDED_LATITUDE, self::ROUNDED_LONGITUDE);
    }

    public function testKeepsTheAddressAndTheTokenOutOfTheLogsOfTheCommand(): void
    {
        // When
        $token = $this->createdToken();

        // Then
        self::assertNothingWrittenContains(self::ADDRESS, $token, AccessToken::hash($token));
        self::assertNoTokenWritten();
    }

    public function testKeepsTheTokenOutOfTheLogsOfARequestWithIt(): void
    {
        // Given
        $token = $this->createdToken();

        // When
        $this->client->request(Request::METHOD_POST, self::BOOKMARKS, server: [self::CONTENT_TYPE => self::JSON, self::AUTHORIZATION => self::BEARER.$token], content: self::UNKNOWN_DISCOVERY);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertNothingWrittenContains(self::ADDRESS, $token, AccessToken::hash($token));
        self::assertNoTokenWritten();
    }

    public function testKeepsAWrongTokenOutOfTheLogs(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, self::BOOKMARKS, server: [self::AUTHORIZATION => self::WRONG_TOKEN]);

        // Then
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertNothingWrittenContains(self::WRONG_TOKEN);
        self::assertNoTokenWritten();
    }

    private static function assertNoTokenWritten(): void
    {
        self::assertDoesNotMatchRegularExpression(self::TOKEN, implode(self::LINE_BREAK, self::writtenLogs()));
    }

    private function createdToken(): string
    {
        $output = self::runCommand(self::COMMAND, [self::EMAIL_ARGUMENT => self::ADDRESS])->getDisplay();
        self::assertSame(1, preg_match(self::TOKEN, $output, $matches));

        return $matches[0];
    }

    private static function assertNothingWrittenContains(string ...$secrets): void
    {
        self::assertNotEmpty(self::writtenLogs());
        $written = implode(self::LINE_BREAK, self::writtenLogs());

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $written);
        }
    }

    /**
     * @return list<string>
     */
    private static function writtenLogs(): array
    {
        $written = array_filter(self::logHandler()->getRecords(), self::isWrittenInProd(...));

        return array_values(array_map(self::formatted(...), $written));
    }

    private static function formatted(LogRecord $record): string
    {
        self::assertIsString($record->formatted);

        return $record->formatted;
    }

    private static function pictureUnavailableTwice(): UpstreamMock
    {
        return UpstreamMock::create()
            ->onThisDayFrom(self::MONTH_DAY)
            ->archiveOn(self::DAY)
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE)
            ->apod()->status(Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private static function isWrittenInProd(LogRecord $record): bool
    {
        if (\array_key_exists($record->channel, self::PROD_LEVELS)) {
            return self::PROD_LEVELS[$record->channel]?->includes($record->level) ?? false;
        }

        return Level::Info->includes($record->level);
    }
}
