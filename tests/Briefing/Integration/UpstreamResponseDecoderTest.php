<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\Exception\InvalidUpstreamPayloadException;
use App\Briefing\PayloadDefect;
use App\Briefing\Source\UpstreamResponseDecoder;
use App\Tests\Support\Briefing\ExampleApiResponse;
use App\Tests\Support\Briefing\ExampleItem;
use App\Tests\Support\InteractsWithTestServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class UpstreamResponseDecoderTest extends KernelTestCase
{
    use InteractsWithTestServices;

    private const string URL = 'https://upstream.test/resource';

    public function testDecodesTheApiNamesIntoATypedObject(): void
    {
        // Given
        $response = self::response('{"title":"Pillars","media_type":"image","url":"https://apod.nasa.gov/p.jpg","service_version":"v1"}');

        // When
        $decoded = self::decoder()->decode($response, ExampleApiResponse::class);

        // Then
        self::assertEquals(new ExampleApiResponse('Pillars', 'image', 'https://apod.nasa.gov/p.jpg'), $decoded);
    }

    public function testDecodesAListOfObjects(): void
    {
        // Given
        $response = self::response('{"title":"On this day","media_type":"text","items":[{"text":"Moon landing","year":1969,"pages":[]}]}');

        // When
        $decoded = self::decoder()->decode($response, ExampleApiResponse::class);

        // Then
        self::assertEquals([new ExampleItem('Moon landing', 1969)], $decoded->items);
    }

    public function testLeavesAMissingOptionalFieldEmpty(): void
    {
        // When
        $decoded = self::decoder()->decode(self::response('{"title":"Pillars","media_type":"other"}'), ExampleApiResponse::class);

        // Then
        self::assertNull($decoded->url);
    }

    /**
     * @param list<string> $expectedFields
     */
    #[DataProvider('invalidBodies')]
    public function testRejectsABodyNamingOnlyTheFailedFields(string $body, string $expectedMessage, array $expectedFields, PayloadDefect $expectedDefect): void
    {
        // Given
        $response = self::response($body);

        try {
            // When
            self::decoder()->decode($response, ExampleApiResponse::class);
            self::fail('An invalid response must be rejected.');
        } catch (InvalidUpstreamPayloadException $exception) {
            // Then
            self::assertSame($expectedMessage, $exception->getMessage());
            self::assertSame($expectedFields, $exception->fields());
            self::assertSame($expectedDefect, $exception->defect());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>, PayloadDefect}>
     */
    public static function invalidBodies(): iterable
    {
        $type = ExampleApiResponse::class;

        yield 'malformed JSON' => ['{"title":', "Upstream response for {$type} is not valid JSON.", [], PayloadDefect::NotJson];
        yield 'not JSON' => ['<html>NASA Science</html>', "Upstream response for {$type} is not valid JSON.", [], PayloadDefect::NotJson];
        yield 'empty body' => ['', "Upstream response for {$type} is not valid JSON.", [], PayloadDefect::NotJson];
        yield 'missing field' => ['{"media_type":"image"}', "Upstream response does not match {$type} at title.", ['title'], PayloadDefect::WrongShape];
        yield 'error body' => ['{"code":400,"msg":"Date must be between Jun 16, 1995 and Nov 20, 2026."}', "Upstream response does not match {$type} at title, mediaType.", ['title', 'mediaType'], PayloadDefect::WrongShape];
        yield 'list' => ['[{"title":"Pillars","media_type":"image"}]', "Upstream response does not match {$type} at title, mediaType.", ['title', 'mediaType'], PayloadDefect::WrongShape];
        yield 'number instead of text' => ['{"title":20261118,"media_type":"image"}', "Upstream response does not match {$type} at title.", ['title'], PayloadDefect::WrongShape];
        yield 'null instead of text' => ['{"title":null,"media_type":"image"}', "Upstream response does not match {$type} at title.", ['title'], PayloadDefect::WrongShape];
        yield 'text instead of a number in a list' => ['{"title":"Pillars","media_type":"image","items":[{"text":"Moon landing","year":"1969"}]}', "Upstream response does not match {$type} at items[0].year.", ['items[0].year'], PayloadDefect::WrongShape];
        yield 'text instead of an object in a list' => ['{"title":"Pillars","media_type":"image","items":["secret"]}', "Upstream response does not match {$type} at items[0].text, items[0].year.", ['items[0].text', 'items[0].year'], PayloadDefect::WrongShape];
        yield 'several wrong fields' => ['{"media_type":"image","items":[{"year":"1969"}]}', "Upstream response does not match {$type} at title, items[0].text, items[0].year.", ['title', 'items[0].text', 'items[0].year'], PayloadDefect::WrongShape];
        yield 'JSON text instead of an object' => ['"Pillars"', "Upstream response does not match {$type} at title, mediaType.", ['title', 'mediaType'], PayloadDefect::WrongShape];
        yield 'blank title' => ['{"title":" ","media_type":"image"}', "Upstream response {$type} is invalid at title.", ['title'], PayloadDefect::InvalidValue];
        yield 'empty url' => ['{"title":"Pillars","media_type":"image","url":""}', "Upstream response {$type} is invalid at url.", ['url'], PayloadDefect::InvalidValue];
        yield 'url with another scheme' => ['{"title":"Pillars","media_type":"image","url":"ftp://secret.example/52.23"}', "Upstream response {$type} is invalid at url.", ['url'], PayloadDefect::InvalidValue];
        yield 'blank text in a list' => ['{"title":"Pillars","media_type":"image","items":[{"text":" ","year":1969}]}', "Upstream response {$type} is invalid at items[0].text.", ['items[0].text'], PayloadDefect::InvalidValue];
    }

    private static function decoder(): UpstreamResponseDecoder
    {
        return self::testService(UpstreamResponseDecoder::class);
    }

    private static function response(string $body): ResponseInterface
    {
        return new MockHttpClient(new MockResponse($body))->request(Request::METHOD_GET, self::URL);
    }
}
