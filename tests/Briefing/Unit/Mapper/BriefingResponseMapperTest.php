<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Mapper;

use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Discovery\DiscoveryExtractor;
use App\Briefing\Dto\Response\ApodDto;
use App\Briefing\Dto\Response\ApodMediaType;
use App\Briefing\Dto\Response\AttributionDto;
use App\Briefing\Dto\Response\BriefingResponse;
use App\Briefing\Dto\Response\EventDto;
use App\Briefing\Dto\Response\LocationDto;
use App\Briefing\Dto\Response\PageDto;
use App\Briefing\Dto\Response\PersonDto;
use App\Briefing\Dto\Response\SourceError;
use App\Briefing\Dto\Response\SourceFailedDto;
use App\Briefing\Dto\Response\SourceKey;
use App\Briefing\Dto\Response\SourceNotApplicableDto;
use App\Briefing\Dto\Response\SourceOkDto;
use App\Briefing\Dto\Response\SourcesDto;
use App\Briefing\Dto\Response\SourceStaleDto;
use App\Briefing\Dto\Response\WeatherDto;
use App\Briefing\Dto\Response\WeatherKindValue;
use App\Briefing\FailureKind;
use App\Briefing\Mapper\BriefingResponseMapper;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\MediaType;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
use App\Briefing\Model\WeatherKind;
use App\Briefing\SourceName;
use App\Tests\Support\Briefing\BriefingResultBuilder;
use App\Tests\Support\Briefing\SourcePayloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BriefingResponseMapperTest extends TestCase
{
    private const string CAESAR = 'https://en.wikipedia.org/wiki/Julius_Caesar';
    private const string HAUSER = 'https://en.wikipedia.org/wiki/Wings_Hauser';
    private const string THUMBNAIL = 'https://upload.wikimedia.org/hauser.jpg';
    private const string CHECKED_AT = '2026-11-18T09:30:00+01:00';

    public function testMapsEveryFieldOfABriefingWithData(): void
    {
        // Given
        $picture = new ApodPicture(new \DateTimeImmutable(BriefingResultBuilder::DATE), 'Pillars', 'Gas and dust.', MediaType::Image, 'https://apod.nasa.gov/a.jpg', 'https://apod.nasa.gov/a-hd.jpg', 'Hubble', new Attribution('Astronomy Picture of the Day', 'https://apod.nasa.gov/', null, null, true, null));
        $history = new OnThisDayFeed(
            [new HistoricalEvent(-44, 'Julius Caesar is assassinated.', [new PageRef('Julius Caesar', self::CAESAR, self::THUMBNAIL)])],
            [new NotablePerson(1947, 'Wings Hauser', 'American actor', self::HAUSER, self::THUMBNAIL)],
            [new NotablePerson(-44, 'Julius Caesar', null, self::CAESAR, null)],
            new Attribution('Wikipedia', 'https://en.wikipedia.org/', 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/', true, null),
        );
        $weather = new DailyWeather(WeatherKind::Final, 'Europe/Warsaw', 1.2, 6.8, 0.4, 18.0, 3, '2026-11-05T06:44:00+01:00', '2026-11-05T16:02:00+01:00', 33480, new Attribution('Open-Meteo', 'https://open-meteo.com/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', true, 'Generated using Copernicus Climate Change Service information'));
        $briefing = BriefingResultBuilder::create()->ok(SourceName::Apod, $picture)->ok(SourceName::OnThisDay, $history)->ok(SourceName::Weather, $weather)->attemptedAt(self::CHECKED_AT)->build();
        $ok = new SourceOkDto(new \DateTimeImmutable(self::CHECKED_AT), false);

        // When
        $response = new BriefingResponseMapper(new DiscoveryExtractor())->create($briefing);

        // Then
        self::assertEquals(
            new BriefingResponse(
                BriefingResultBuilder::DATE,
                new LocationDto(BriefingResultBuilder::LATITUDE, BriefingResultBuilder::LONGITUDE, null, false),
                new SourcesDto($ok, $ok, $ok),
                [],
                new ApodDto(DiscoveryId::fromString('d16fbc34-e63e-5d48-9914-6d048b66a20a'), 'Pillars', 'Gas and dust.', ApodMediaType::Image, 'https://apod.nasa.gov/a.jpg', 'https://apod.nasa.gov/a-hd.jpg', 'Hubble'),
                [new EventDto(DiscoveryId::fromString('cceb60e2-30ae-570b-9905-b4b06b700682'), -44, 'Julius Caesar is assassinated.', [new PageDto('Julius Caesar', self::CAESAR, self::THUMBNAIL)])],
                [new PersonDto(DiscoveryId::fromString('ea4bbfc2-451d-5320-892a-3779e0c01d78'), 1947, 'Wings Hauser', 'American actor', self::HAUSER, self::THUMBNAIL)],
                [new PersonDto(DiscoveryId::fromString('2c55be6f-61ec-5bda-82b1-792f92934dad'), -44, 'Julius Caesar', null, self::CAESAR, null)],
                new WeatherDto(WeatherKindValue::Final, 'Europe/Warsaw', 1.2, 6.8, 0.4, 18.0, 3, 'Overcast', '2026-11-05T06:44:00+01:00', '2026-11-05T16:02:00+01:00', 33480),
                [
                    new AttributionDto(SourceKey::Apod, 'Astronomy Picture of the Day', 'https://apod.nasa.gov/', null, null, true, null),
                    new AttributionDto(SourceKey::OnThisDay, 'Wikipedia', 'https://en.wikipedia.org/', 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/', true, null),
                    new AttributionDto(SourceKey::Weather, 'Open-Meteo', 'https://open-meteo.com/', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', true, 'Generated using Copernicus Climate Change Service information'),
                ],
            ),
            $response,
        );
    }

    #[DataProvider('mediaTypes')]
    public function testMapsEveryMediaType(MediaType $mediaType, ApodMediaType $expectedMediaType): void
    {
        // Given
        $briefing = BriefingResultBuilder::create()->ok(SourceName::Apod, SourcePayloads::apodPicture($mediaType))->build();

        // When
        $response = new BriefingResponseMapper(new DiscoveryExtractor())->create($briefing);

        // Then
        self::assertSame($expectedMediaType, $response->apod?->mediaType);
    }

    /**
     * @return iterable<string, array{MediaType, ApodMediaType}>
     */
    public static function mediaTypes(): iterable
    {
        yield 'image' => [MediaType::Image, ApodMediaType::Image];
        yield 'video' => [MediaType::Video, ApodMediaType::Video];
        yield 'other' => [MediaType::Other, ApodMediaType::Other];
    }

    #[DataProvider('failures')]
    public function testMapsAFailedSourceAsMissingWithItsError(FailureKind $kind, SourceError $expectedError): void
    {
        // Given
        $briefing = BriefingResultBuilder::create()->failed(SourceName::Apod, $kind)->attemptedAt(self::CHECKED_AT)->build();

        // When
        $response = new BriefingResponseMapper(new DiscoveryExtractor())->create($briefing);

        // Then
        self::assertEquals(new SourceFailedDto($expectedError, new \DateTimeImmutable(self::CHECKED_AT)), $response->sources->apod);
        self::assertSame([SourceKey::Apod], $response->missing);
        self::assertNull($response->apod);
    }

    /**
     * @return iterable<string, array{FailureKind, SourceError}>
     */
    public static function failures(): iterable
    {
        yield 'unavailable' => [FailureKind::Unavailable, SourceError::Unavailable];
        yield 'rate limited' => [FailureKind::RateLimited, SourceError::RateLimited];
        yield 'rejected' => [FailureKind::Rejected, SourceError::Rejected];
        yield 'misconfigured' => [FailureKind::Misconfigured, SourceError::Misconfigured];
        yield 'invalid payload' => [FailureKind::InvalidPayload, SourceError::InvalidPayload];
    }

    public function testMapsTheLastGoodDataOfAFailedRefreshAsStale(): void
    {
        // Given
        $briefing = BriefingResultBuilder::create()->stale(SourceName::Weather, SourcePayloads::dailyWeather(), '2026-11-18T09:00:00Z', FailureKind::Unavailable)->attemptedAt(self::CHECKED_AT)->build();

        // When
        $response = new BriefingResponseMapper(new DiscoveryExtractor())->create($briefing);

        // Then
        self::assertEquals(new SourceStaleDto(new \DateTimeImmutable('2026-11-18T09:00:00Z'), SourceError::Unavailable, new \DateTimeImmutable(self::CHECKED_AT)), $response->sources->weather);
        self::assertSame([], $response->missing);
        self::assertSame(WeatherKindValue::Final, $response->weather?->kind);
    }

    public function testMapsASourceThatDoesNotCoverTheDate(): void
    {
        // Given
        $briefing = BriefingResultBuilder::create()->notApplicable(SourceName::Apod)->build();

        // When
        $response = new BriefingResponseMapper(new DiscoveryExtractor())->create($briefing);

        // Then
        self::assertEquals(new SourceNotApplicableDto(), $response->sources->apod);
        self::assertSame([SourceKey::Apod], $response->missing);
    }

    #[DataProvider('weatherKinds')]
    public function testMapsEveryWeatherKind(WeatherKind $kind, WeatherKindValue $expected): void
    {
        // Given
        $briefing = BriefingResultBuilder::create()->ok(SourceName::Weather, SourcePayloads::dailyWeather($kind))->build();

        // When
        $response = new BriefingResponseMapper(new DiscoveryExtractor())->create($briefing);

        // Then
        self::assertSame($expected, $response->weather?->kind);
    }

    /**
     * @return iterable<string, array{WeatherKind, WeatherKindValue}>
     */
    public static function weatherKinds(): iterable
    {
        yield 'final' => [WeatherKind::Final, WeatherKindValue::Final];
        yield 'provisional' => [WeatherKind::Provisional, WeatherKindValue::Provisional];
        yield 'forecast' => [WeatherKind::Forecast, WeatherKindValue::Forecast];
    }

    /**
     * @return iterable<string, array{?int, ?string}>
     */
    public static function weatherCodes(): iterable
    {
        yield 'known code' => [61, 'Slight rain'];
        yield 'clear sky' => [0, 'Clear sky'];
        yield 'unknown code' => [42, null];
        yield 'no code' => [null, null];
    }

    #[DataProvider('weatherCodes')]
    public function testDescribesTheWeatherCode(?int $code, ?string $expected): void
    {
        // Given
        $briefing = BriefingResultBuilder::create()->ok(SourceName::Weather, SourcePayloads::dailyWeather(weatherCode: $code))->build();

        // When
        $response = new BriefingResponseMapper(new DiscoveryExtractor())->create($briefing);

        // Then
        self::assertSame($expected, $response->weather?->description);
    }

    public function testLeavesTheWeatherEmptyWhenItHasNoData(): void
    {
        // Given
        $briefing = BriefingResultBuilder::create()->failed(SourceName::Weather, FailureKind::Rejected)->build();

        // When
        $response = new BriefingResponseMapper(new DiscoveryExtractor())->create($briefing);

        // Then
        self::assertNull($response->weather);
        self::assertEquals(new SourceFailedDto(SourceError::Rejected, new \DateTimeImmutable(BriefingResultBuilder::ATTEMPTED_AT)), $response->sources->weather);
        self::assertSame([SourceKey::Weather], $response->missing);
        self::assertSame([SourceKey::Apod, SourceKey::OnThisDay], array_map(static fn (AttributionDto $attribution): SourceKey => $attribution->source, $response->attributions));
    }

    public function testLeavesTheHistoryListsEmptyWhenOnThisDayHasNoData(): void
    {
        // Given
        $briefing = BriefingResultBuilder::create()->failed(SourceName::OnThisDay, FailureKind::Unavailable)->build();

        // When
        $response = new BriefingResponseMapper(new DiscoveryExtractor())->create($briefing);

        // Then
        self::assertEquals(new SourceFailedDto(SourceError::Unavailable, new \DateTimeImmutable(BriefingResultBuilder::ATTEMPTED_AT)), $response->sources->onThisDay);
        self::assertSame([SourceKey::OnThisDay], $response->missing);
        self::assertSame([[], [], []], [$response->events, $response->births, $response->deaths]);
        self::assertSame([SourceKey::Apod, SourceKey::Weather], array_map(static fn (AttributionDto $attribution): SourceKey => $attribution->source, $response->attributions));
    }

    public function testListsMissingSourcesInSourceOrder(): void
    {
        // Given
        $briefing = BriefingResultBuilder::create()->failed(SourceName::Weather, FailureKind::Unavailable)->notApplicable(SourceName::Apod)->build();

        // When
        $response = new BriefingResponseMapper(new DiscoveryExtractor())->create($briefing);

        // Then
        self::assertSame([SourceKey::Apod, SourceKey::Weather], $response->missing);
    }
}
