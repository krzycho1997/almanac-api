<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Integration\Source\Apod;

use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\MediaType;
use App\Briefing\Source\Apod\ApodSource;
use App\Briefing\SourceResult;
use App\Briefing\SourceStatus;
use App\Tests\Support\Briefing\UpstreamMock;
use App\Tests\Support\Briefing\UpstreamMockResponseFactory;
use App\Tests\Support\InteractsWithTestServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;

final class ApodClientTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithTestServices;

    private const string DATE = '2024-03-15';

    #[DataProvider('dates')]
    public function testCoversDaysFromTheFirstPictureToTodayInTheUnitedStates(string $now, string $date, bool $covered): void
    {
        // Given
        self::mockTime($now);

        // When
        $covers = self::source()->client()->covers(new \DateTimeImmutable($date));

        // Then
        self::assertSame($covered, $covers);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function dates(): iterable
    {
        yield 'day before the first picture' => ['2026-11-20 12:00:00 UTC', '1995-06-15', false];
        yield 'first picture' => ['2026-11-20 12:00:00 UTC', '1995-06-16', true];
        yield 'today' => ['2026-11-20 12:00:00 UTC', '2026-11-20', true];
        yield 'tomorrow' => ['2026-11-20 12:00:00 UTC', '2026-11-21', false];
        yield 'already tomorrow in UTC, still today in New York' => ['2026-11-21 02:00:00 UTC', '2026-11-21', false];
        yield 'today in New York while UTC is a day ahead' => ['2026-11-21 02:00:00 UTC', '2026-11-20', true];
        yield 'last winter second before midnight in New York' => ['2026-01-15 04:59:59 UTC', '2026-01-15', false];
        yield 'winter midnight in New York' => ['2026-01-15 05:00:00 UTC', '2026-01-15', true];
        yield 'last summer second before midnight in New York' => ['2026-07-15 03:59:59 UTC', '2026-07-15', false];
        yield 'summer midnight in New York' => ['2026-07-15 04:00:00 UTC', '2026-07-15', true];
    }

    public function testAsksNasaForThePictureOfTheDate(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod());

        // When
        self::fetch('2024-03-05');

        // Then
        $request = self::upstream()->served()[0];
        self::assertSame('https://api.nasa.gov/planetary/apod?date=2024-03-05', $request->getRequestUrl());
    }

    public function testMapsThePictureNasaForwardsFromItsNewSite(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture('apod/passthrough-2024-03-15.json'));

        // When
        $result = self::fetch(self::DATE);

        // Then
        self::assertSame(SourceStatus::Ok, $result->status);
        self::assertInstanceOf(ApodPicture::class, $result->payload);
        self::assertSame('Portrait of NGC 1055', $result->payload->title);
        self::assertStringStartsWith('Big, beautiful spiral galaxy NGC 1055 is a dominant member of a small galaxy group', $result->payload->explanation);
        self::assertSame(MediaType::Image, $result->payload->mediaType);
        self::assertStringStartsWith('Image Credit & Copyright:', (string) $result->payload->credit);
    }

    public function testKeepsOnlyThePictureTextFromAWordPressExplanation(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture('apod/passthrough-video-2021-06-23.json'));

        // When
        $result = self::fetch('2021-06-23');

        // Then
        self::assertInstanceOf(ApodPicture::class, $result->payload);
        self::assertStringEndsWith('the effect of the jets in limiting the masses of subsequently formed stars.', $result->payload->explanation);
    }

    #[DataProvider('daysWithTheGenericThumbnail')]
    public function testDropsTheGenericThumbnailNasaGivesInsteadOfTheMedia(string $fixture, string $date, MediaType $mediaType): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture($fixture));

        // When
        $result = self::fetch($date);

        // Then
        self::assertSame(SourceStatus::Ok, $result->status);
        self::assertInstanceOf(ApodPicture::class, $result->payload);
        self::assertSame($mediaType, $result->payload->mediaType);
        self::assertNull($result->payload->url);
        self::assertNull($result->payload->hdUrl);
    }

    /**
     * @return iterable<string, array{string, string, MediaType}>
     */
    public static function daysWithTheGenericThumbnail(): iterable
    {
        yield 'video' => ['apod/passthrough-video-2021-06-23.json', '2021-06-23', MediaType::Video];
        yield 'first picture' => ['apod/passthrough-first-picture-1995-06-16.json', '1995-06-16', MediaType::Image];
    }

    public function testLeavesOutAnEmptyCredit(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture('apod/passthrough-first-picture-1995-06-16.json'));

        // When
        $result = self::fetch('1995-06-16');

        // Then
        self::assertInstanceOf(ApodPicture::class, $result->payload);
        self::assertSame('Neutron Star Earth', $result->payload->title);
        self::assertNull($result->payload->credit);
    }

    public function testRejectsThePictureOfAnotherDate(): void
    {
        // Given
        self::upstream()->respondWith(UpstreamMock::create()->apod()->fixture('apod/passthrough-2024-03-15.json'));

        // When
        $result = self::fetch('2024-03-14');

        // Then
        self::assertSame(SourceStatus::Failed, $result->status);
        self::assertSame(FailureKind::InvalidPayload, $result->failureKind);
    }

    #[DataProvider('failures')]
    public function testReportsAFailedPicture(UpstreamMock $scenario, FailureKind $kind): void
    {
        // Given
        self::upstream()->respondWith($scenario);

        // When
        $result = self::fetch(self::DATE);

        // Then
        self::assertSame(SourceStatus::Failed, $result->status);
        self::assertSame($kind, $result->failureKind);
    }

    /**
     * @return iterable<string, array{UpstreamMock, FailureKind}>
     */
    public static function failures(): iterable
    {
        yield 'NASA Science placeholder' => [UpstreamMock::create()->apod()->fixture('apod/placeholder-2026-11-18.json'), FailureKind::InvalidPayload];
        yield 'date out of range' => [UpstreamMock::create()->apod()->fixture('apod/date-out-of-range.json')->status(Response::HTTP_BAD_REQUEST), FailureKind::Rejected];
        yield 'server error twice' => [UpstreamMock::create()->apod()->status(Response::HTTP_INTERNAL_SERVER_ERROR)->apod()->status(Response::HTTP_INTERNAL_SERVER_ERROR), FailureKind::Unavailable];
        yield 'empty object' => [UpstreamMock::create()->apod(), FailureKind::InvalidPayload];
        yield 'empty picture address' => [UpstreamMock::create()->apod()->json('{"date":"2024-03-15","title":"Pillars","explanation":"Gas","media_type":"image","url":""}'), FailureKind::InvalidPayload];
        yield 'blank media type' => [UpstreamMock::create()->apod()->json('{"date":"2024-03-15","title":"Pillars","explanation":"Gas","media_type":" ","url":"https://apod.nasa.gov/a.jpg"}'), FailureKind::InvalidPayload];
        yield 'relative picture address' => [UpstreamMock::create()->apod()->json('{"date":"2024-03-15","title":"Pillars","explanation":"Gas","media_type":"image","url":"/apod/image.jpg"}'), FailureKind::InvalidPayload];
        yield 'high-resolution address with an unsafe scheme' => [UpstreamMock::create()->apod()->json('{"date":"2024-03-15","title":"Pillars","explanation":"Gas","media_type":"image","url":"https://apod.nasa.gov/a.jpg","hdurl":"javascript:alert(1)"}'), FailureKind::InvalidPayload];
    }

    private static function fetch(string $date): SourceResult
    {
        $source = self::source();

        return $source->client()->request(new \DateTimeImmutable($date), Location::fromCoordinates(52.23, 21.01))->resolve($source->name());
    }

    private static function source(): ApodSource
    {
        return self::testService(ApodSource::class);
    }

    private static function upstream(): UpstreamMockResponseFactory
    {
        return self::testService(UpstreamMockResponseFactory::class);
    }
}
