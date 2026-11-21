<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\EndToEnd\HttpProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class NginxApiDocumentTest extends TestCase
{
    private const string DOCUMENT = '/api/docs.json';

    public function testServesTheApiDocumentAsCacheableCompressedJson(): void
    {
        // When
        $response = HttpProbe::fromEnvironment()->get(self::DOCUMENT, ['Accept-Encoding' => 'gzip']);

        // Then
        self::assertSame(Response::HTTP_OK, $response->status);
        self::assertSame(['application/json'], $response->headerValues('Content-Type'));
        self::assertSame(['max-age=300, public'], $response->headerValues('Cache-Control'));
        self::assertSame(['gzip'], $response->headerValues('Content-Encoding'));
    }
}
