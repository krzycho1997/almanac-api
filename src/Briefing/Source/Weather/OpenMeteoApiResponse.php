<?php

declare(strict_types=1);

namespace App\Briefing\Source\Weather;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class OpenMeteoApiResponse
{
    public const int EASTERNMOST_OFFSET = 50400;
    private const int WESTERNMOST_OFFSET = -43200;
    private const int WHOLE_SECOND = 1;

    public function __construct(
        #[Assert\NotBlank, Assert\Timezone(zone: \DateTimeZone::ALL_WITH_BC)]
        public string $timezone,
        #[SerializedName('utc_offset_seconds'), Assert\DivisibleBy(self::WHOLE_SECOND), Assert\Range(min: self::WESTERNMOST_OFFSET, max: self::EASTERNMOST_OFFSET)]
        public float $utcOffset,
        public ?OpenMeteoDaily $daily = null,
    ) {
    }

    public function offsetSeconds(): int
    {
        return (int) $this->utcOffset;
    }
}
