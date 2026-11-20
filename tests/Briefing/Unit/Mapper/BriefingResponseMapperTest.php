<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Mapper;

use App\Briefing\BriefingResult;
use App\Briefing\Dto\Response\ApodDto;
use App\Briefing\Dto\Response\ApodMediaType;
use App\Briefing\Dto\Response\BriefingResponse;
use App\Briefing\Dto\Response\LocationDto;
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
use App\Briefing\Model\MediaType;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BriefingResponseMapperTest extends TestCase
{
    private const string DATE = '2026-11-05';
    private const string WARSAW = 'Europe/Warsaw';

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
                new SourcesDto(new SourceOkDto(self::checkedAt(), false)),
                [],
                new ApodDto('Pillars', 'Gas and dust.', ApodMediaType::Image, 'https://apod.nasa.gov/a.jpg', null, null),
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
        self::assertEquals(new SourcesDto(new SourceFailedDto($expectedError, self::checkedAt())), $response->sources);
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
        self::assertEquals(new SourcesDto(new SourceNotApplicableDto()), $response->sources);
        self::assertSame([SourceKey::Apod], $response->missing);
    }

    private static function briefingOf(SourceResult $apod): BriefingResult
    {
        return new BriefingResult(new \DateTimeImmutable(self::DATE, new \DateTimeZone(self::WARSAW)), Location::fromCoordinates(52.23, 21.01), [$apod], self::checkedAt());
    }

    private static function checkedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-18 12:00', new \DateTimeZone(self::WARSAW));
    }
}
