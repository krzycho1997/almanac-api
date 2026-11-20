<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing\Recording;

use App\Briefing\Location;
use App\Briefing\SourceName;

final readonly class ClientCall
{
    private const float WARSAW_LATITUDE = 52.23;
    private const float WARSAW_LONGITUDE = 21.01;

    public function __construct(
        public SourceName $source,
        public string $day,
        public string $url,
        public float $latitude = self::WARSAW_LATITUDE,
        public float $longitude = self::WARSAW_LONGITUDE,
    ) {
    }

    public function date(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->day);
    }

    public function location(): Location
    {
        return Location::fromCoordinates($this->latitude, $this->longitude);
    }

    public function host(): string
    {
        return (string) parse_url($this->url, \PHP_URL_HOST);
    }

    public function isMadeWith(self $other): bool
    {
        if ($this->source === $other->source) {
            return $this->day === $other->day;
        }

        return false;
    }
}
