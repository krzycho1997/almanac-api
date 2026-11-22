<?php

declare(strict_types=1);

namespace App\Briefing\Messenger;

use App\Briefing\BriefingDay;
use App\Briefing\Location;
use App\Briefing\Message\PrefetchDateMessage;

final readonly class PrefetchDateMessageMapper
{
    public function convertToMessage(\DateTimeImmutable $date, Location $location): PrefetchDateMessage
    {
        if ($location->isDefault) {
            return new PrefetchDateMessage($date->format(BriefingDay::FORMAT), null, null);
        }

        return new PrefetchDateMessage($date->format(BriefingDay::FORMAT), $location->latitude, $location->longitude);
    }

    public function convertToDay(PrefetchDateMessage $message, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        return BriefingDay::startOf($message->date, $timezone);
    }

    public function convertToLocation(PrefetchDateMessage $message, Location $default): Location
    {
        return Location::fromOptional($message->latitude, $message->longitude, $default);
    }
}
