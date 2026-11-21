<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\EndToEnd\HttpProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class NginxProblemDetailsTest extends TestCase
{
    private const string BOOKMARKS = '/api/bookmarks';
    private const string HEALTH = '/api/health';
    private const int BODY_LIMIT_BYTES = 16 * 1024;
    private const int HEADER_BUFFER_BYTES = 8 * 1024;
    private const string PROBLEM_JSON = 'application/problem+json';

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('rejectedByNginx')]
    public function testAnswersWhatNginxRejectsItselfWithProblemDetails(string $method, string $path, array $headers, string $content, int $status): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->request($method, $path, $headers, $content);

        // Then
        self::assertSame($status, $response->status);
        self::assertSame([self::PROBLEM_JSON], $response->headerValues('Content-Type'));
        self::assertCount(1, $response->headerValues('X-Request-Id'));
        $problem = json_decode($response->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(['type', 'title', 'status', 'detail'], array_keys($problem));
        self::assertSame($status, $problem['status']);
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string, int}>
     */
    public static function rejectedByNginx(): iterable
    {
        yield 'body over the limit' => [Request::METHOD_POST, self::BOOKMARKS, ['Content-Type' => 'application/json'], str_repeat('a', self::BODY_LIMIT_BYTES + 1), Response::HTTP_REQUEST_ENTITY_TOO_LARGE];
        yield 'header over the buffer' => [Request::METHOD_GET, self::HEALTH, ['X-Padding' => str_repeat('a', self::HEADER_BUFFER_BYTES)], '', Response::HTTP_BAD_REQUEST];
    }
}
