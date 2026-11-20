<?php

declare(strict_types=1);

namespace App\Briefing\Mapper;

use App\Briefing\BriefingDay;
use App\Briefing\BriefingResult;
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
use App\Briefing\Dto\Response\SourceStatusDto;
use App\Briefing\Dto\Response\WeatherDto;
use App\Briefing\Dto\Response\WeatherKindValue;
use App\Briefing\FailureKind;
use App\Briefing\Location;
use App\Briefing\Model\ApodPicture;
use App\Briefing\Model\Attribution;
use App\Briefing\Model\DailyWeather;
use App\Briefing\Model\HistoricalEvent;
use App\Briefing\Model\MediaType;
use App\Briefing\Model\NotablePerson;
use App\Briefing\Model\OnThisDayFeed;
use App\Briefing\Model\PageRef;
use App\Briefing\Model\SourcePayload;
use App\Briefing\Model\WeatherKind;
use App\Briefing\Model\WmoCode;
use App\Briefing\SourceName;
use App\Briefing\SourceResult;
use App\Briefing\SourceStatus;

final readonly class BriefingResponseMapper
{
    private const bool NOT_STALE = false;

    public function create(BriefingResult $briefing): BriefingResponse
    {
        $apod = $briefing->resultFor(SourceName::Apod);
        $onThisDay = self::feed($briefing->resultFor(SourceName::OnThisDay));

        return new BriefingResponse(
            self::day($briefing->date),
            self::location($briefing->location),
            self::sources($briefing),
            self::missing($briefing),
            self::apod($apod),
            array_map(self::event(...), $onThisDay->events ?? []),
            array_map(self::person(...), $onThisDay->births ?? []),
            array_map(self::person(...), $onThisDay->deaths ?? []),
            self::weather($briefing->resultFor(SourceName::Weather)),
            self::attributions($briefing),
        );
    }

    private static function day(\DateTimeImmutable $date): string
    {
        return $date->format(BriefingDay::FORMAT);
    }

    private static function sources(BriefingResult $briefing): SourcesDto
    {
        return new SourcesDto(
            self::status($briefing->resultFor(SourceName::Apod), $briefing->checkedAt),
            self::status($briefing->resultFor(SourceName::OnThisDay), $briefing->checkedAt),
            self::status($briefing->resultFor(SourceName::Weather), $briefing->checkedAt),
        );
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

    private static function feed(SourceResult $result): ?OnThisDayFeed
    {
        $feed = $result->payload;

        if ($feed instanceof OnThisDayFeed) {
            return $feed;
        }

        return null;
    }

    private static function event(HistoricalEvent $event): EventDto
    {
        return new EventDto($event->year, $event->text, array_map(self::page(...), $event->pages));
    }

    private static function page(PageRef $page): PageDto
    {
        return new PageDto($page->title, $page->url, $page->thumbnail);
    }

    private static function person(NotablePerson $person): PersonDto
    {
        return new PersonDto($person->year, $person->name, $person->description, $person->url, $person->thumbnail);
    }

    private static function weather(SourceResult $result): ?WeatherDto
    {
        $weather = $result->payload;

        if ($weather instanceof DailyWeather) {
            return new WeatherDto(
                kind: self::weatherKind($weather->kind),
                timezone: $weather->timezone,
                temperatureMin: $weather->temperatureMin,
                temperatureMax: $weather->temperatureMax,
                precipitationSum: $weather->precipitationSum,
                windSpeedMax: $weather->windSpeedMax,
                weatherCode: $weather->weatherCode,
                description: self::weatherDescription($weather->weatherCode),
                sunrise: $weather->sunrise,
                sunset: $weather->sunset,
                daylightDuration: $weather->daylightDuration,
            );
        }

        return null;
    }

    /**
     * @return list<AttributionDto>
     */
    private static function attributions(BriefingResult $briefing): array
    {
        $attributions = [];

        foreach (SourceName::cases() as $source) {
            $attributions[] = self::attributionOf($briefing->resultFor($source));
        }

        return array_values(array_filter($attributions));
    }

    private static function attributionOf(SourceResult $result): ?AttributionDto
    {
        if ($result->payload instanceof SourcePayload) {
            return self::attribution(self::key($result->source), $result->payload->attribution);
        }

        return null;
    }

    private static function attribution(SourceKey $source, Attribution $attribution): AttributionDto
    {
        return new AttributionDto($source, $attribution->name, $attribution->url, $attribution->license, $attribution->licenseUrl, $attribution->modified, $attribution->notice);
    }

    private static function weatherDescription(?int $code): ?string
    {
        if (null === $code) {
            return null;
        }

        return WmoCode::tryFrom($code)?->description();
    }

    private static function weatherKind(WeatherKind $kind): WeatherKindValue
    {
        return match ($kind) {
            WeatherKind::Final => WeatherKindValue::Final,
            WeatherKind::Provisional => WeatherKindValue::Provisional,
            WeatherKind::Forecast => WeatherKindValue::Forecast,
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
