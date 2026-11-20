<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\FailureKind;
use App\Briefing\Mapper\BriefingResponseMapper;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
use App\Briefing\Model\WeatherKind;
use App\Briefing\SourceName;
use App\Tests\Support\Briefing\BriefingResultBuilder;
use App\Tests\Support\Briefing\SourcePayloads;
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
    private const string CHECKED_AT = '2026-11-18T12:00:00+01:00';

    /**
     * @param array<string, mixed> $expectedSource
     */
    #[DataProvider('sourcesWithoutData')]
    public function testDescribesSourcesWithoutDataAsOpenApiSays(BriefingResultBuilder $briefingResult, array $expectedSource): void
    {
        // Given
        $response = new BriefingResponseMapper()->create($briefingResult->attemptedAt(self::CHECKED_AT)->build());

        // When
        $briefing = self::serialized($response);

        // Then
        new SchemaValidator()->validate($briefing, OpenApiDocument::instance()->schemas()[self::SCHEMA]);
        self::assertSame(['apod' => $expectedSource, 'onThisDay' => $expectedSource, 'weather' => $expectedSource], $briefing['sources']);
        self::assertSame(['apod', 'onThisDay', 'weather'], $briefing['missing']);
        self::assertSame([null, [], [], [], null], [$briefing['apod'], $briefing['events'], $briefing['births'], $briefing['deaths'], $briefing['weather']]);
    }

    public function testDescribesStaleDataAsOpenApiSays(): void
    {
        // Given
        $response = new BriefingResponseMapper()->create(BriefingResultBuilder::create()->stale(SourceName::Weather, SourcePayloads::dailyWeather(), '2026-11-18T09:00:00+01:00', FailureKind::RateLimited)->attemptedAt(self::CHECKED_AT)->build());

        // When
        $briefing = self::serialized($response);

        // Then
        new SchemaValidator()->validate($briefing, OpenApiDocument::instance()->schemas()[self::SCHEMA]);
        self::assertSame(['status' => 'ok', 'fetchedAt' => '2026-11-18T08:00:00Z', 'stale' => true, 'error' => 'rate_limited', 'lastAttemptAt' => '2026-11-18T11:00:00Z'], $briefing['sources']['weather']);
    }

    public function testDescribesTheDayInHistoryAsOpenApiSays(): void
    {
        // Given
        $feed = new OnThisDayFeed(
            [new HistoricalEvent(-44, 'Julius Caesar is assassinated.', [new PageRef('Julius Caesar', 'https://en.wikipedia.org/wiki/Julius_Caesar', null)])],
            [new NotablePerson(1947, 'Wings Hauser', 'American actor', 'https://en.wikipedia.org/wiki/Wings_Hauser', 'https://upload.wikimedia.org/hauser.jpg')],
            [new NotablePerson(-44, 'Julius Caesar', null, 'https://en.wikipedia.org/wiki/Julius_Caesar', null)],
            new Attribution('Wikipedia', 'https://en.wikipedia.org/', 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/', true, null),
        );
        $response = new BriefingResponseMapper()->create(BriefingResultBuilder::create()->notApplicable(SourceName::Apod)->ok(SourceName::OnThisDay, $feed)->notApplicable(SourceName::Weather)->build());

        // When
        $briefing = self::serialized($response);

        // Then
        new SchemaValidator()->validate($briefing, OpenApiDocument::instance()->schemas()[self::SCHEMA]);
        self::assertSame([['year' => -44, 'text' => 'Julius Caesar is assassinated.', 'pages' => [['title' => 'Julius Caesar', 'url' => 'https://en.wikipedia.org/wiki/Julius_Caesar', 'thumbnail' => null]]]], $briefing['events']);
        self::assertSame([['year' => 1947, 'name' => 'Wings Hauser', 'description' => 'American actor', 'url' => 'https://en.wikipedia.org/wiki/Wings_Hauser', 'thumbnail' => 'https://upload.wikimedia.org/hauser.jpg']], $briefing['births']);
    }

    /**
     * @return iterable<string, array{BriefingResultBuilder, array<string, mixed>}>
     */
    public static function sourcesWithoutData(): iterable
    {
        foreach (FailureKind::cases() as $kind) {
            yield $kind->value => [BriefingResultBuilder::create()->failed(SourceName::Apod, $kind)->failed(SourceName::OnThisDay, $kind)->failed(SourceName::Weather, $kind), ['status' => 'failed', 'error' => $kind->value, 'lastAttemptAt' => '2026-11-18T11:00:00Z']];
        }

        yield 'not applicable' => [BriefingResultBuilder::create()->notApplicable(SourceName::Apod)->notApplicable(SourceName::OnThisDay)->notApplicable(SourceName::Weather), ['status' => 'not_applicable']];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('weatherDays')]
    public function testDescribesTheWeatherAsOpenApiSays(DailyWeather $weather, array $expected): void
    {
        // Given
        $response = new BriefingResponseMapper()->create(BriefingResultBuilder::create()->notApplicable(SourceName::Apod)->notApplicable(SourceName::OnThisDay)->ok(SourceName::Weather, $weather)->build());

        // When
        $briefing = self::serialized($response);

        // Then
        new SchemaValidator()->validate($briefing, OpenApiDocument::instance()->schemas()[self::SCHEMA]);
        self::assertSame($expected, $briefing['weather']);
    }

    /**
     * @return iterable<string, array{DailyWeather, array<string, mixed>}>
     */
    public static function weatherDays(): iterable
    {
        yield 'ordinary day' => [
            new DailyWeather(WeatherKind::Final, 'Europe/Warsaw', 1.2, 7.0, 0.0, 18.0, 3, '2024-07-01T04:15:00+02:00', '2024-07-01T21:01:00+02:00', 60360, self::openMeteo()),
            ['kind' => 'final', 'timezone' => 'Europe/Warsaw', 'temperatureMin' => 1.2, 'temperatureMax' => 7.0, 'precipitationSum' => 0.0, 'windSpeedMax' => 18.0, 'weatherCode' => 3, 'description' => 'Overcast', 'sunrise' => '2024-07-01T04:15:00+02:00', 'sunset' => '2024-07-01T21:01:00+02:00', 'daylightDuration' => 60360],
        ];
        yield 'polar day with missing measures' => [
            new DailyWeather(WeatherKind::Forecast, 'Arctic/Longyearbyen', null, 9.3, null, null, null, null, null, 86400, self::openMeteo()),
            ['kind' => 'forecast', 'timezone' => 'Arctic/Longyearbyen', 'temperatureMin' => null, 'temperatureMax' => 9.3, 'precipitationSum' => null, 'windSpeedMax' => null, 'weatherCode' => null, 'description' => null, 'sunrise' => null, 'sunset' => null, 'daylightDuration' => 86400],
        ];
    }

    private static function openMeteo(): Attribution
    {
        return new Attribution('Open-Meteo', 'https://open-meteo.com/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', true, null);
    }

    /**
     * @return array{sources: array{apod: array<string, mixed>, onThisDay: array<string, mixed>, weather: array<string, mixed>}, missing: list<string>, apod: mixed, events: list<mixed>, births: list<mixed>, deaths: list<mixed>, weather: mixed}
     */
    private static function serialized(object $response): array
    {
        $json = self::testService(SerializerInterface::class)->serialize($response, JsonEncoder::FORMAT);

        /** @var array{sources: array{apod: array<string, mixed>, onThisDay: array<string, mixed>, weather: array<string, mixed>}, missing: list<string>, apod: mixed, events: list<mixed>, births: list<mixed>, deaths: list<mixed>, weather: mixed} $briefing */
        $briefing = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $briefing;
    }
}
