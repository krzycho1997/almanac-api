<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\OpenApi;

use App\Shared\RequestId\RequestIdListener;
use App\Tests\Support\OpenApi\OpenApiContract;
use App\Tests\Support\OpenApi\OpenApiDocument;
use App\Tests\Support\RequestIds;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class HealthDocumentationTest extends TestCase
{
    private const string HEALTH = '/api/health';

    #[DataProvider('broken')]
    public function testRejectsAHealthReportTheCodeNeverSends(string $body): void
    {
        // Given
        $contract = new OpenApiContract(OpenApiDocument::instance());
        $response = new Response($body, Response::HTTP_OK, ['Content-Type' => 'application/json', RequestIdListener::HEADER => RequestIds::FROM_NGINX]);

        // When / Then
        $this->expectException(AssertionFailedError::class);
        $contract->assertHonoured(Request::create(self::HEALTH), $response, self::HEALTH);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function broken(): iterable
    {
        yield 'no checks' => ['{"status":"ok"}'];
        yield 'another status' => ['{"status":"degraded","checks":{}}'];
        yield 'checks as a list' => ['{"status":"ok","checks":[]}'];
    }
}
