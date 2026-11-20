<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\EndToEnd\HttpProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class NginxHardeningTest extends TestCase
{
    private const string HEALTH = '/api/health';
    private const array SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        'Referrer-Policy' => 'no-referrer',
    ];
    private const int BODY_LIMIT_BYTES = 16 * 1024;
    private const int HEADER_BUFFER_BYTES = 8 * 1024;
    private const array OCTET_STREAM = ['Content-Type' => 'application/octet-stream'];
    private const string DIRECTIVE_SEPARATOR = ',';

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('responses')]
    public function testSendsTheSecurityHeaders(string $method, string $path, array $headers, string $content, int $status): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->request($method, $path, $headers, $content);

        // Then
        self::assertSame($status, $response->status);
        foreach (self::SECURITY_HEADERS as $name => $value) {
            self::assertSame([$value], $response->headerValues($name), $name);
        }
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string, int}>
     */
    public static function responses(): iterable
    {
        yield 'from Symfony' => [Request::METHOD_GET, self::HEALTH, [], '', Response::HTTP_OK];
        yield 'not found by Symfony' => [Request::METHOD_GET, '/api/missing', [], '', Response::HTTP_NOT_FOUND];
        yield 'not found by nginx' => [Request::METHOD_GET, '/missing.php', [], '', Response::HTTP_NOT_FOUND];
        yield 'body too large for nginx' => [Request::METHOD_POST, self::HEALTH, self::OCTET_STREAM, str_repeat('a', self::BODY_LIMIT_BYTES + 1), Response::HTTP_REQUEST_ENTITY_TOO_LARGE];
        yield 'header too large for nginx' => [Request::METHOD_GET, self::HEALTH, ['X-Padding' => str_repeat('a', self::HEADER_BUFFER_BYTES)], '', Response::HTTP_BAD_REQUEST];
    }

    public function testHidesTheServerVersionAndPhp(): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get(self::HEALTH);

        // Then
        self::assertSame(['nginx'], $response->headerValues('Server'));
        self::assertSame([], $response->headerValues('X-Powered-By'));
    }

    public function testForbidsCachingOfBookmarks(): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get('/api/bookmarks');

        // Then
        self::assertContains('no-store', self::directives($response->headerValues('Cache-Control')));
    }

    public function testAcceptsABodyAtTheLimit(): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->post(self::HEALTH, str_repeat('a', self::BODY_LIMIT_BYTES), self::OCTET_STREAM);

        // Then
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->status);
    }

    /**
     * @param list<string> $headerValues
     *
     * @return list<string>
     */
    private static function directives(array $headerValues): array
    {
        return array_map(trim(...), explode(self::DIRECTIVE_SEPARATOR, implode(self::DIRECTIVE_SEPARATOR, $headerValues)));
    }
}
