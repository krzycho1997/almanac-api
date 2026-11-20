<?php

declare(strict_types=1);

namespace App\Briefing\Mapper;

use App\Briefing\BriefingDay;
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
use App\Briefing\Dto\Response\SourceStatusDto;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\MediaType;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Briefing\SourceStatus;

final readonly class BriefingResponseMapper
{
    private const bool NOT_STALE = false;

    public function create(BriefingResult $briefing): BriefingResponse
    {
        $apod = $briefing->resultFor(SourceName::Apod);

        return new BriefingResponse(
            self::day($briefing->date),
            self::location($briefing->location),
            self::sources($apod, $briefing->checkedAt),
            self::missing($briefing),
            self::apod($apod),
        );
    }

    private static function day(\DateTimeImmutable $date): string
    {
        return $date->format(BriefingDay::FORMAT);
    }

    private static function sources(SourceResult $apod, \DateTimeImmutable $checkedAt): SourcesDto
    {
        return new SourcesDto(self::status($apod, $checkedAt));
    }

    /**
     * @return list<SourceKey>
     */
    private static function missing(BriefingResult $briefing): array
    {
        return array_map(self::key(...), $briefing->missing());
    }

    private static function location(Location $location): LocationDto
    {
        return new LocationDto($location->latitude, $location->longitude, $location->name, $location->isDefault);
    }

    private static function status(SourceResult $result, \DateTimeImmutable $checkedAt): SourceStatusDto
    {
        return match ($result->status) {
            SourceStatus::Ok => new SourceOkDto($checkedAt, self::NOT_STALE),
            SourceStatus::Failed => new SourceFailedDto(self::error(self::failure($result)), $checkedAt),
            SourceStatus::NotApplicable => new SourceNotApplicableDto(),
        };
    }

    private static function failure(SourceResult $result): FailureKind
    {
        return $result->failureKind ?? throw new \LogicException("The failed {$result->source->value} result has no failure kind.");
    }

    private static function error(FailureKind $kind): SourceError
    {
        return match ($kind) {
            FailureKind::Unavailable => SourceError::Unavailable,
            FailureKind::RateLimited => SourceError::RateLimited,
            FailureKind::Rejected => SourceError::Rejected,
            FailureKind::Misconfigured => SourceError::Misconfigured,
            FailureKind::InvalidPayload => SourceError::InvalidPayload,
        };
    }

    private static function mediaType(MediaType $mediaType): ApodMediaType
    {
        return match ($mediaType) {
            MediaType::Image => ApodMediaType::Image,
            MediaType::Video => ApodMediaType::Video,
            MediaType::Other => ApodMediaType::Other,
        };
    }

    private static function key(SourceName $source): SourceKey
    {
        return match ($source) {
            SourceName::Apod => SourceKey::Apod,
            SourceName::OnThisDay => SourceKey::OnThisDay,
            SourceName::Weather => SourceKey::Weather,
        };
    }

    private static function apod(SourceResult $result): ?ApodDto
    {
        $picture = $result->payload;

        if ($picture instanceof ApodPicture) {
            return new ApodDto($picture->title, $picture->explanation, self::mediaType($picture->mediaType), $picture->url, $picture->hdUrl, $picture->credit);
        }

        return null;
    }
}
