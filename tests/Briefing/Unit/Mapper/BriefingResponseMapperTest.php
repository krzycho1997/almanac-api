<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Mapper;

use App\Briefing\BriefingResult;
use App\Briefing\Dto\Response\ApodDto;
use App\Briefing\Dto\Response\ApodMediaType;
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
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Mapper\BriefingResponseMapper;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\MediaType;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BriefingResponseMapperTest extends TestCase
{
    private const string DATE = '2026-11-05';
    private const string WARSAW = 'Europe/Warsaw';
    private const string CAESAR = 'https://en.wikipedia.org/wiki/Julius_Caesar';
    private const string HAUSER = 'https://en.wikipedia.org/wiki/Wings_Hauser';
    private const string THUMBNAIL = 'https://upload.wikimedia.org/hauser.jpg';

    public function testMapsAPictureWithItsSourceStatus(): void
    {
        // Given
        $picture = new ApodPicture(new \DateTimeImmutable(self::DATE), 'Pillars', 'Gas and dust.', MediaType::Image, 'https://apod.nasa.gov/a.jpg', null, null);
        $briefing = self::briefingOf(SourceResult::ok(SourceName::Apod, $picture));

        // When
        $response = new BriefingResponseMapper()->create($briefing);

        // Then
        self::assertEquals(
            new BriefingResponse(
                self::DATE,
                new LocationDto(52.23, 21.01, null, false),
                new SourcesDto(new SourceOkDto(self::checkedAt(), false), new SourceOkDto(self::checkedAt(), false)),
                [],
                new ApodDto('Pillars', 'Gas and dust.', ApodMediaType::Image, 'https://apod.nasa.gov/a.jpg', null, null),
                [new EventDto(-44, 'Julius Caesar is assassinated.', [new PageDto('Julius Caesar', self::CAESAR, self::THUMBNAIL)])],
                [new PersonDto(1947, 'Wings Hauser', 'American actor', self::HAUSER, self::THUMBNAIL)],
                [new PersonDto(-44, 'Julius Caesar', null, self::CAESAR, null)],
            ),
            $response,
        );
    }

    #[DataProvider('mediaTypes')]
    public function testMapsEveryMediaType(MediaType $mediaType, ApodMediaType $expectedMediaType): void
    {
        // Given
        $picture = new ApodPicture(new \DateTimeImmutable(self::DATE), 'Pillars', 'Gas and dust.', $mediaType, null, null, null);

        // When
        $response = new BriefingResponseMapper()->create(self::briefingOf(SourceResult::ok(SourceName::Apod, $picture)));

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
        $briefing = self::briefingOf(SourceResult::failed(SourceName::Apod, $kind));

        // When
        $response = new BriefingResponseMapper()->create($briefing);

        // Then
        self::assertEquals(new SourceFailedDto($expectedError, self::checkedAt()), $response->sources->apod);
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

    public function testMapsASourceThatDoesNotCoverTheDate(): void
    {
        // Given
        $briefing = self::briefingOf(SourceResult::notApplicable(SourceName::Apod));

        // When
        $response = new BriefingResponseMapper()->create($briefing);

        // Then
        self::assertEquals(new SourceNotApplicableDto(), $response->sources->apod);
        self::assertSame([SourceKey::Apod], $response->missing);
    }

    public function testLeavesTheHistoryListsEmptyWhenOnThisDayHasNoData(): void
    {
        // Given
        $picture = new ApodPicture(new \DateTimeImmutable(self::DATE), 'Pillars', 'Gas and dust.', MediaType::Image, null, null, null);
        $briefing = self::briefingOf(SourceResult::ok(SourceName::Apod, $picture), SourceResult::failed(SourceName::OnThisDay, FailureKind::Unavailable));

        // When
        $response = new BriefingResponseMapper()->create($briefing);

        // Then
        self::assertEquals(new SourceFailedDto(SourceError::Unavailable, self::checkedAt()), $response->sources->onThisDay);
        self::assertSame([SourceKey::OnThisDay], $response->missing);
        self::assertSame([[], [], []], [$response->events, $response->births, $response->deaths]);
    }

    private static function briefingOf(SourceResult $apod, ?SourceResult $onThisDay = null): BriefingResult
    {
        return new BriefingResult(new \DateTimeImmutable(self::DATE, new \DateTimeZone(self::WARSAW)), Location::fromCoordinates(52.23, 21.01), [$apod, $onThisDay ?? self::history()], self::checkedAt());
    }

    private static function history(): SourceResult
    {
        return SourceResult::ok(SourceName::OnThisDay, new OnThisDayFeed(
            [new HistoricalEvent(-44, 'Julius Caesar is assassinated.', [new PageRef('Julius Caesar', self::CAESAR, self::THUMBNAIL)])],
            [new NotablePerson(1947, 'Wings Hauser', 'American actor', self::HAUSER, self::THUMBNAIL)],
            [new NotablePerson(-44, 'Julius Caesar', null, self::CAESAR, null)],
        ));
    }

    private static function checkedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-18 12:00', new \DateTimeZone(self::WARSAW));
    }
}
