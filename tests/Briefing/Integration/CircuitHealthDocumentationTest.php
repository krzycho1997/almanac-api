<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Shared\RequestId\RequestIdListener;
use App\Tests\Support\OpenApi\OpenApiContract;
use App\Tests\Support\OpenApi\OpenApiDocument;
use App\Tests\Support\RequestIds;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CircuitHealthDocumentationTest extends TestCase
{
    private const string HEALTH = '/api/health';

    #[DataProvider('broken')]
    public function testRejectsACircuitReportTheCodeNeverSends(string $body): void
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
        yield 'no circuits' => ['{"status":"ok","checks":{}}'];
        yield 'undocumented check' => ['{"status":"ok","checks":{"circuits":{"apod":"closed","onThisDay":"closed","weather":"closed"},"queue":{}}}'];
        yield 'missing circuit' => ['{"status":"ok","checks":{"circuits":{"apod":"closed","onThisDay":"closed"}}}'];
        yield 'undocumented circuit' => ['{"status":"ok","checks":{"circuits":{"apod":"closed","onThisDay":"closed","weather":"closed","moon":"closed"}}}'];
        yield 'undocumented state' => ['{"status":"ok","checks":{"circuits":{"apod":"closed","onThisDay":"closed","weather":"unknown"}}}'];
    }
}
