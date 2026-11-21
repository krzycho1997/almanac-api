<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\EndToEnd\HttpProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class NginxCompressionTest extends TestCase
{
    private const string JSON = '/api/health';
    private const string PROBLEM_JSON = '/api/missing';
    private const string BAD_REQUEST = '/api/briefings/not-a-date';
    private const string CONTENT_ENCODING = 'Content-Encoding';
    private const string GZIP = 'gzip';
    private const string VARY = 'Vary';
    private const string ACCEPT_ENCODING = 'Accept-Encoding';
    private const string VARY_SEPARATOR = ',';
    private const array ACCEPTS_GZIP = [self::ACCEPT_ENCODING => self::GZIP];
    private const array ACCEPTS_GZIP_THROUGH_A_PROXY = [self::ACCEPT_ENCODING => self::GZIP, 'Via' => '1.1 proxy'];

    #[DataProvider('responses')]
    public function testCompressesForAClientThatAcceptsGzip(string $path, int $status): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get($path, self::ACCEPTS_GZIP);

        // Then
        self::assertSame($status, $response->status);
        self::assertSame([self::GZIP], $response->headerValues(self::CONTENT_ENCODING));
        self::assertContains(self::ACCEPT_ENCODING, self::varyValues($response->headerValues(self::VARY)));
    }

    public function testCompressesForAClientBehindAProxy(): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get(self::JSON, self::ACCEPTS_GZIP_THROUGH_A_PROXY);

        // Then
        self::assertSame(Response::HTTP_OK, $response->status);
        self::assertSame([self::GZIP], $response->headerValues(self::CONTENT_ENCODING));
    }

    #[DataProvider('responses')]
    public function testSendsTheBodyAsItIsToAClientWithoutGzip(string $path, int $status): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get($path);

        // Then
        self::assertSame($status, $response->status);
        self::assertSame([], $response->headerValues(self::CONTENT_ENCODING));
    }

    public function testLeavesAnErrorOtherThanNotFoundUncompressed(): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get(self::BAD_REQUEST, self::ACCEPTS_GZIP);

        // Then
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->status);
        self::assertSame([], $response->headerValues(self::CONTENT_ENCODING));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function responses(): iterable
    {
        yield 'JSON' => [self::JSON, Response::HTTP_OK];
        yield 'Problem Details' => [self::PROBLEM_JSON, Response::HTTP_NOT_FOUND];
    }

    /**
     * @param list<string> $headerValues
     *
     * @return list<string>
     */
    private static function varyValues(array $headerValues): array
    {
        return array_map(trim(...), explode(self::VARY_SEPARATOR, implode(self::VARY_SEPARATOR, $headerValues)));
    }
}
