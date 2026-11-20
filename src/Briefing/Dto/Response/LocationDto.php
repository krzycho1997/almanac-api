<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class LocationDto
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $name,
        public bool $isDefault,
    ) {
    }
}
