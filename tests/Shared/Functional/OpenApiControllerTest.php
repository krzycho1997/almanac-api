<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\OpenApi\OpenApiDocument;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

final class OpenApiControllerTest extends FunctionalTestCase
{
    public function testServesTheDocumentAsJson(): void
    {
        // When
        $this->client->request(Request::METHOD_GET, '/api/docs.json');

        // Then
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertJsonStringEqualsJsonString(self::documentAsJson(), $body);
        self::assertStringContainsString('"temperatureMax":10.0', $body);
        self::assertStringContainsString('"/api/docs.json"', $body);
    }

    public function testLetsClientsCacheTheDocument(): void
    {
        // When
        $this->client->request(Request::METHOD_HEAD, '/api/docs.json');

        // Then
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'max-age=300, public');
    }

    private static function documentAsJson(): string
    {
        return json_encode(Yaml::parseFile(OpenApiDocument::PATH, Yaml::PARSE_OBJECT_FOR_MAP), \JSON_THROW_ON_ERROR);
    }
}
