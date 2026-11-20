<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\BriefingResult;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Mapper\BriefingResponseMapper;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Tests\Support\InteractsWithTestServices;
use App\Tests\Support\OpenApi\OpenApiDocument;
use League\OpenAPIValidation\Schema\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

final class BriefingResponseSchemaTest extends KernelTestCase
{
    use InteractsWithTestServices;

    private const string SCHEMA = 'Briefing';
    private const string DATE = '2026-11-18';
    private const string CHECKED_AT = '2026-11-18T12:00:00+01:00';

    /**
     * @param array<string, mixed> $expectedSource
     */
    #[DataProvider('sourcesWithoutData')]
    public function testDescribesASourceWithoutDataAsOpenApiSays(SourceResult $apod, array $expectedSource): void
    {
        // Given
        $response = new BriefingResponseMapper()->create(new BriefingResult(new \DateTimeImmutable(self::DATE), Location::fromCoordinates(52.23, 21.01), [$apod], new \DateTimeImmutable(self::CHECKED_AT)));

        // When
        $briefing = self::serialized($response);

        // Then
        new SchemaValidator()->validate($briefing, OpenApiDocument::instance()->schemas()[self::SCHEMA]);
        self::assertSame($expectedSource, $briefing['sources']['apod']);
        self::assertSame(['apod'], $briefing['missing']);
        self::assertNull($briefing['apod']);
    }

    /**
     * @return iterable<string, array{SourceResult, array<string, mixed>}>
     */
    public static function sourcesWithoutData(): iterable
    {
        foreach (FailureKind::cases() as $kind) {
            yield $kind->value => [SourceResult::failed(SourceName::Apod, $kind), ['status' => 'failed', 'error' => $kind->value, 'lastAttemptAt' => '2026-11-18T11:00:00Z']];
        }

        yield 'not applicable' => [SourceResult::notApplicable(SourceName::Apod), ['status' => 'not_applicable']];
    }

    /**
     * @return array{sources: array{apod: array<string, mixed>}, missing: list<string>, apod: mixed}
     */
    private static function serialized(object $response): array
    {
        $json = self::testService(SerializerInterface::class)->serialize($response, JsonEncoder::FORMAT);

        /** @var array{sources: array{apod: array<string, mixed>}, missing: list<string>, apod: mixed} $briefing */
        $briefing = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $briefing;
    }
}
