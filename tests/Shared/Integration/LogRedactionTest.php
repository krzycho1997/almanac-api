<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration;

use App\Shared\Logging\AllowedLogContextProcessor;
use App\Shared\Logging\LogContextKey;
use App\Tests\Support\InteractsWithLogs;
use App\Tests\Support\RequestIds;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LogRedactionTest extends KernelTestCase
{
    use InteractsWithLogs;

    private const array JWT_SEGMENTS = ['eyJhbGciOiJIUzI1NiJ9', 'eyJzdWIiOiIxMjM0In0', 'c2lnbmF0dXJl'];
    private const string FRAMEWORK_LOGGER = 'monolog.logger.request';
    private const string SENSITIVE_VALUE = 'plain-sensitive-value';
    private const string NEXT_PARAMETER = '&date=2026-11-18';
    private const string MESSAGE = 'message';
    private const int BEYOND_THE_BACKTRACK_LIMIT = 1_100_000;

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('sensitiveData')]
    public function testRedactsSecretsAndPersonalDataFromTheWrittenRecord(string $message, array $context, string $secret): void
    {
        // Given
        $logger = self::frameworkLogger();

        // When
        $logger->warning($message, $context);

        // Then
        $written = self::writtenRecord();
        self::assertStringNotContainsString($secret, $written);
        self::assertWholeJsonRecord($written);
        self::assertStringContainsString(AllowedLogContextProcessor::MASK, $written);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function sensitiveData(): iterable
    {
        yield 'email in the message' => ['User jan.kowalski@example.com logged in', [], 'jan.kowalski@example.com'];
        yield 'coordinates in a URL' => ['GET /api/briefings/2026-11-18?lat=52.2297&lon=21.0122', [], '52.2297'];
        yield 'NASA key in a URL' => ['GET https://api.nasa.gov/planetary/apod?api_key=nasa-secret-key&date=2026-11-18', [], 'nasa-secret-key'];
        yield 'token in a URL' => ['GET /api/bookmarks?token=url-secret-token', [], 'url-secret-token'];
        yield 'Bearer token in the message' => ['Rejected Bearer abc.def-123_secret', [], 'abc.def-123_secret'];
        yield 'secrets inside an exception' => ['Source failed', ['exception' => new \RuntimeException('GET https://api.nasa.gov/x?api_key=exception-secret-key failed for anna@example.com')], 'exception-secret-key'];
        yield 'email inside an exception' => ['Source failed', ['exception' => new \RuntimeException('Rejected anna@example.com')], 'anna@example.com'];
        yield 'email key' => ['User created', ['email' => 'piotr@example.com'], 'piotr@example.com'];
        yield 'latitude key' => ['Briefing built', ['lat' => '52.2297'], '52.2297'];
        yield 'longitude key' => ['Briefing built', ['lon' => '21.0122'], '21.0122'];
        yield 'authorization header' => ['Upstream call', ['headers' => ['Authorization' => 'Basic dXNlcjpwYXNz']], 'dXNlcjpwYXNz'];
        yield 'api key header' => ['Upstream call', ['headers' => ['X-Api-Key' => 'header-secret-key']], 'header-secret-key'];
        yield 'password' => ['Login attempt', ['password' => 'hunter2-secret'], 'hunter2-secret'];
        yield 'token' => ['Token issued', ['token' => 'issued-secret-token'], 'issued-secret-token'];
        yield 'access token in a URL' => ['GET /callback?access_token=url-access-token', [], 'url-access-token'];
        yield 'camel-case API key in a URL' => ['GET https://api.example.com/x?apiKey=camel-api-key', [], 'camel-api-key'];
        yield 'bare JWT' => ['Rejected '.implode('.', self::JWT_SEGMENTS), [], self::JWT_SEGMENTS[1]];
        yield 'Basic credentials in the message' => ['Upstream sent Basic dXNlcjpwYXNzd29yZA==', [], 'dXNlcjpwYXNzd29yZA'];
        yield 'database password in a DSN' => ['Cannot connect to postgresql://almanac:db-secret-pass@database:5432/almanac', [], 'db-secret-pass'];
        yield 'email with a Polish local part' => ['User zażółć@example.pl logged in', [], 'zażółć@example.pl'];
        yield 'interpolated email' => ['User {email} created', ['email' => 'interpolated@example.com'], 'interpolated@example.com'];
        yield 'secret in a previous exception' => ['Source failed', ['exception' => new \RuntimeException('outer', previous: new \RuntimeException('GET /x?api_key=previous-secret-key'))], 'previous-secret-key'];
        yield 'access token key' => ['Token refreshed', ['access_token' => 'context-access-token'], 'context-access-token'];
        yield 'spelled-out latitude key' => ['Briefing built', ['latitude' => '52.2297'], '52.2297'];
    }

    #[DataProvider('sensitiveKeys')]
    public function testRedactsTheValueOfEverySensitiveKey(string $key): void
    {
        // Given
        $logger = self::frameworkLogger();

        // When
        $logger->warning('Upstream call', [$key => self::SENSITIVE_VALUE]);

        // Then
        self::assertStringNotContainsString(self::SENSITIVE_VALUE, self::writtenRecord());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveKeys(): iterable
    {
        $keys = [
            'authorization', 'Proxy-Authorization', 'auth', 'token', 'access_token', 'accessToken', 'refresh_token', 'refreshToken',
            'id_token', 'idToken', 'api_key', 'api-key', 'apiKey', 'X-Api-Key', 'X-Auth-Token', 'X-Access-Token',
            'password', 'passwd', 'pwd', 'secret', 'app_secret', 'client_secret', 'clientSecret', 'cookie', 'Set-Cookie',
            'email', 'lat', 'lon', 'lng', 'latitude', 'longitude', 'database_url', 'dsn',
        ];

        foreach ($keys as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('sensitiveUrlParameters')]
    public function testRedactsOnlyTheValueOfASensitiveUrlParameter(string $parameter): void
    {
        // Given
        $logger = self::frameworkLogger();

        // When
        $logger->warning("GET https://api.example.com/x?{$parameter}=".self::SENSITIVE_VALUE.self::NEXT_PARAMETER);

        // Then
        self::assertSame(
            "GET https://api.example.com/x?{$parameter}=".AllowedLogContextProcessor::MASK.self::NEXT_PARAMETER,
            self::writtenMessage(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveUrlParameters(): iterable
    {
        foreach (['api_key', 'api-key', 'apiKey', 'access_token', 'refresh_token', 'token', 'lat', 'lon', 'lng', 'latitude', 'longitude'] as $parameter) {
            yield $parameter => [$parameter];
        }
    }

    public function testKeepsTheFragmentAfterARedactedUrlParameter(): void
    {
        // Given
        $logger = self::frameworkLogger();

        // When
        $logger->warning('GET /x?lat='.self::SENSITIVE_VALUE.'#forecast');

        // Then
        self::assertSame('GET /x?lat='.AllowedLogContextProcessor::MASK.'#forecast', self::writtenMessage());
    }

    #[DataProvider('credentials')]
    public function testRedactsTheWholeCredential(string $message, string $redacted): void
    {
        // Given
        $logger = self::frameworkLogger();

        // When
        $logger->warning($message);

        // Then
        self::assertSame($redacted, self::writtenMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function credentials(): iterable
    {
        yield 'Basic credentials with padding' => ['Upstream sent Basic dXNlcjpwYXNzd29yZA==', 'Upstream sent Basic '.AllowedLogContextProcessor::MASK];
        yield 'shortest Bearer token' => ['Rejected Bearer abcdefgh', 'Rejected Bearer '.AllowedLogContextProcessor::MASK];
    }

    public function testKeepsTheStackTraceOfAnException(): void
    {
        // Given
        $logger = self::frameworkLogger();

        // When
        $logger->critical('Uncaught exception', [LogContextKey::Exception->value => new \RuntimeException('Source failed')]);

        // Then
        $record = self::decodedRecord();
        self::assertIsArray($record['context']);
        self::assertIsArray($record['context'][LogContextKey::Exception->value]);
        self::assertNotEmpty($record['context'][LogContextKey::Exception->value]['trace']);
    }

    public function testKeepsAVeryLongMessageThatLooksLikeTheStartOfADsn(): void
    {
        // Given
        $logger = self::frameworkLogger();
        $message = 'Upstream echoed postgresql://user:'.str_repeat('a', self::BEYOND_THE_BACKTRACK_LIMIT);

        // When
        $logger->critical($message);

        // Then
        self::assertSame($message, self::writtenMessage());
    }

    public function testKeepsTheDataNeededToDiagnoseProblems(): void
    {
        // Given
        $logger = self::frameworkLogger();

        // When
        $logger->warning('Source {source} fetch failed: {kind}', [
            LogContextKey::Source->value => 'apod',
            LogContextKey::Kind->value => 'unavailable',
            LogContextKey::Date->value => '2026-11-18',
            LogContextKey::HttpStatus->value => 503,
            LogContextKey::UserId->value => '0b5e4a52-6a8f-4d4b-9a0c-3e1f2d7c8b90',
            'requestId' => RequestIds::FROM_NGINX,
            'version' => '8.5.12',
        ]);

        // Then
        $written = self::writtenRecord();
        self::assertWholeJsonRecord($written);
        self::assertStringNotContainsString(AllowedLogContextProcessor::MASK, $written);
        foreach (['apod', 'unavailable', '2026-11-18', '503', '0b5e4a52-6a8f-4d4b-9a0c-3e1f2d7c8b90', RequestIds::FROM_NGINX, '8.5.12'] as $kept) {
            self::assertStringContainsString($kept, $written);
        }
    }

    public function testKeepsAWwwAuthenticateChallengeReadable(): void
    {
        // Given
        $logger = self::frameworkLogger();

        // When
        $logger->warning('Responded with WWW-Authenticate: Bearer error="invalid_token"');

        // Then
        self::assertStringContainsString('Bearer error=', self::writtenRecord());
    }

    private static function assertWholeJsonRecord(string $written): void
    {
        $record = json_decode($written, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertArrayHasKey(self::MESSAGE, $record);
        self::assertArrayHasKey('channel', $record);
        self::assertArrayHasKey('level_name', $record);
    }

    private static function frameworkLogger(): LoggerInterface
    {
        return self::getContainer()->get(self::FRAMEWORK_LOGGER);
    }

    private static function writtenMessage(): string
    {
        $message = self::decodedRecord()[self::MESSAGE];
        self::assertIsString($message);

        return $message;
    }

    /**
     * @return array<mixed>
     */
    private static function decodedRecord(): array
    {
        $record = json_decode(self::writtenRecord(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($record);

        return $record;
    }

    private static function writtenRecord(): string
    {
        $records = self::logHandler()->getRecords();
        self::assertNotEmpty($records);
        $written = end($records)->formatted;
        self::assertIsString($written);

        return $written;
    }
}
