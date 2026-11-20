<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\Location;
use App\Tests\Support\OpenApi\OpenApiDocument;
use cebe\openapi\Reader;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BriefingQueryDocumentationTest extends TestCase
{
    private const string PATH = '/api/briefings/{date}';

    #[DataProvider('coordinates')]
    public function testDocumentsTheSameBoundsAsTheServerChecks(string $name, float $limit): void
    {
        // When
        $schema = self::schemaOf($name);

        // Then
        self::assertEquals(-$limit, $schema->minimum);
        self::assertEquals($limit, $schema->maximum);
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function coordinates(): iterable
    {
        yield 'latitude' => ['lat', Location::MAX_LATITUDE];
        yield 'longitude' => ['lon', Location::MAX_LONGITUDE];
    }

    private static function schemaOf(string $name): Schema
    {
        foreach (self::parameters() as $parameter) {
            if ($name === $parameter->name) {
                return self::schema($parameter);
            }
        }

        self::fail("openapi.yaml does not document the {$name} query parameter.");
    }

    /**
     * @return list<Parameter>
     */
    private static function parameters(): array
    {
        $parameters = Reader::readFromYamlFile(OpenApiDocument::PATH)->paths[self::PATH]->get->parameters ?? [];

        return array_values(array_filter($parameters, static fn (mixed $parameter): bool => $parameter instanceof Parameter));
    }

    private static function schema(Parameter $parameter): Schema
    {
        if ($parameter->schema instanceof Schema) {
            return $parameter->schema;
        }

        self::fail("openapi.yaml gives the {$parameter->name} query parameter no schema.");
    }
}
