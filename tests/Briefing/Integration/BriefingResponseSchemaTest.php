<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\BriefingResult;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Mapper\BriefingResponseMapper;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
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
    public function testDescribesSourcesWithoutDataAsOpenApiSays(SourceResult $apod, SourceResult $onThisDay, array $expectedSource): void
    {
        // Given
        $response = new BriefingResponseMapper()->create(self::briefingOf($apod, $onThisDay));

        // When
        $briefing = self::serialized($response);

        // Then
        new SchemaValidator()->validate($briefing, OpenApiDocument::instance()->schemas()[self::SCHEMA]);
        self::assertSame(['apod' => $expectedSource, 'onThisDay' => $expectedSource], $briefing['sources']);
        self::assertSame(['apod', 'onThisDay'], $briefing['missing']);
        self::assertSame([null, [], [], []], [$briefing['apod'], $briefing['events'], $briefing['births'], $briefing['deaths']]);
    }

    public function testDescribesTheDayInHistoryAsOpenApiSays(): void
    {
        // Given
        $feed = new OnThisDayFeed(
            [new HistoricalEvent(-44, 'Julius Caesar is assassinated.', [new PageRef('Julius Caesar', 'https://en.wikipedia.org/wiki/Julius_Caesar', null)])],
            [new NotablePerson(1947, 'Wings Hauser', 'American actor', 'https://en.wikipedia.org/wiki/Wings_Hauser', 'https://upload.wikimedia.org/hauser.jpg')],
            [new NotablePerson(-44, 'Julius Caesar', null, 'https://en.wikipedia.org/wiki/Julius_Caesar', null)],
        );
        $response = new BriefingResponseMapper()->create(self::briefingOf(SourceResult::notApplicable(SourceName::Apod), SourceResult::ok(SourceName::OnThisDay, $feed)));

        // When
        $briefing = self::serialized($response);

        // Then
        new SchemaValidator()->validate($briefing, OpenApiDocument::instance()->schemas()[self::SCHEMA]);
        self::assertSame([['year' => -44, 'text' => 'Julius Caesar is assassinated.', 'pages' => [['title' => 'Julius Caesar', 'url' => 'https://en.wikipedia.org/wiki/Julius_Caesar', 'thumbnail' => null]]]], $briefing['events']);
        self::assertSame([['year' => 1947, 'name' => 'Wings Hauser', 'description' => 'American actor', 'url' => 'https://en.wikipedia.org/wiki/Wings_Hauser', 'thumbnail' => 'https://upload.wikimedia.org/hauser.jpg']], $briefing['births']);
    }

    /**
     * @return iterable<string, array{SourceResult, SourceResult, array<string, mixed>}>
     */
    public static function sourcesWithoutData(): iterable
    {
        foreach (FailureKind::cases() as $kind) {
            yield $kind->value => [SourceResult::failed(SourceName::Apod, $kind), SourceResult::failed(SourceName::OnThisDay, $kind), ['status' => 'failed', 'error' => $kind->value, 'lastAttemptAt' => '2026-11-18T11:00:00Z']];
        }

        yield 'not applicable' => [SourceResult::notApplicable(SourceName::Apod), SourceResult::notApplicable(SourceName::OnThisDay), ['status' => 'not_applicable']];
    }

    private static function briefingOf(SourceResult $apod, SourceResult $onThisDay): BriefingResult
    {
        return new BriefingResult(new \DateTimeImmutable(self::DATE), Location::fromCoordinates(52.23, 21.01), [$apod, $onThisDay], new \DateTimeImmutable(self::CHECKED_AT));
    }

    /**
     * @return array{sources: array{apod: array<string, mixed>, onThisDay: array<string, mixed>}, missing: list<string>, apod: mixed, events: list<mixed>, births: list<mixed>, deaths: list<mixed>}
     */
    private static function serialized(object $response): array
    {
        $json = self::testService(SerializerInterface::class)->serialize($response, JsonEncoder::FORMAT);

        /** @var array{sources: array{apod: array<string, mixed>, onThisDay: array<string, mixed>}, missing: list<string>, apod: mixed, events: list<mixed>, births: list<mixed>, deaths: list<mixed>} $briefing */
        $briefing = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $briefing;
    }
}
