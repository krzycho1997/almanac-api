<?php

declare(strict_types=1);

namespace App\Briefing\Message;

use App\Briefing\BriefingDay;
use App\Briefing\Location;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(self::TRANSPORT)]
final readonly class PrefetchDateMessage
{
    public const string TRANSPORT = 'async';

    public function __construct(
        public string $date,
        public ?float $latitude,
        public ?float $longitude,
    ) {
    }

    public static function of(\DateTimeImmutable $date, Location $location): self
    {
        if ($location->isDefault) {
            return new self($date->format(BriefingDay::FORMAT), null, null);
        }

        return new self($date->format(BriefingDay::FORMAT), $location->latitude, $location->longitude);
    }

    public function dayIn(\DateTimeZone $timezone): ?\DateTimeImmutable
    {
        return BriefingDay::startOf($this->date, $timezone);
    }

    public function locationOr(Location $default): Location
    {
        return Location::fromOptional($this->latitude, $this->longitude, $default);
    }
}
