<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration;

use App\Briefing\BriefingResult;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Mapper\BriefingResponseMapper;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
use App\Briefing\Model\WeatherKind;
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
    public function testDescribesSourcesWithoutDataAsOpenApiSays(SourceResult $apod, SourceResult $onThisDay, SourceResult $weather, array $expectedSource): void
    {
        // Given
        $response = new BriefingResponseMapper()->create(self::briefingOf($apod, $onThisDay, $weather));

        // When
        $briefing = self::serialized($response);

        // Then
        new SchemaValidator()->validate($briefing, OpenApiDocument::instance()->schemas()[self::SCHEMA]);
        self::assertSame(['apod' => $expectedSource, 'onThisDay' => $expectedSource, 'weather' => $expectedSource], $briefing['sources']);
        self::assertSame(['apod', 'onThisDay', 'weather'], $briefing['missing']);
        self::assertSame([null, [], [], [], null], [$briefing['apod'], $briefing['events'], $briefing['births'], $briefing['deaths'], $briefing['weather']]);
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
        $response = new BriefingResponseMapper()->create(self::briefingOf(SourceResult::notApplicable(SourceName::Apod), SourceResult::ok(SourceName::OnThisDay, $feed), SourceResult::notApplicable(SourceName::Weather)));

        // When
        $briefing = self::serialized($response);

        // Then
        new SchemaValidator()->validate($briefing, OpenApiDocument::instance()->schemas()[self::SCHEMA]);
        self::assertSame([['year' => -44, 'text' => 'Julius Caesar is assassinated.', 'pages' => [['title' => 'Julius Caesar', 'url' => 'https://en.wikipedia.org/wiki/Julius_Caesar', 'thumbnail' => null]]]], $briefing['events']);
        self::assertSame([['year' => 1947, 'name' => 'Wings Hauser', 'description' => 'American actor', 'url' => 'https://en.wikipedia.org/wiki/Wings_Hauser', 'thumbnail' => 'https://upload.wikimedia.org/hauser.jpg']], $briefing['births']);
    }

    /**
     * @return iterable<string, array{SourceResult, SourceResult, SourceResult, array<string, mixed>}>
     */
    public static function sourcesWithoutData(): iterable
    {
        foreach (FailureKind::cases() as $kind) {
            yield $kind->value => [SourceResult::failed(SourceName::Apod, $kind), SourceResult::failed(SourceName::OnThisDay, $kind), SourceResult::failed(SourceName::Weather, $kind), ['status' => 'failed', 'error' => $kind->value, 'lastAttemptAt' => '2026-11-18T11:00:00Z']];
        }

        yield 'not applicable' => [SourceResult::notApplicable(SourceName::Apod), SourceResult::notApplicable(SourceName::OnThisDay), SourceResult::notApplicable(SourceName::Weather), ['status' => 'not_applicable']];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('weatherDays')]
    public function testDescribesTheWeatherAsOpenApiSays(DailyWeather $weather, array $expected): void
    {
        // Given
        $response = new BriefingResponseMapper()->create(self::briefingOf(SourceResult::notApplicable(SourceName::Apod), SourceResult::notApplicable(SourceName::OnThisDay), SourceResult::ok(SourceName::Weather, $weather)));

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

    private static function briefingOf(SourceResult $apod, SourceResult $onThisDay, SourceResult $weather): BriefingResult
    {
        return new BriefingResult(new \DateTimeImmutable(self::DATE), Location::fromCoordinates(52.23, 21.01), [$apod, $onThisDay, $weather], new \DateTimeImmutable(self::CHECKED_AT));
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
