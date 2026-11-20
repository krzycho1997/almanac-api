<?php

declare(strict_types=1);

namespace App\Briefing\Source\Apod;

use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class ApodApiResponse
{
    public function __construct(
        public string $date,
        public string $title,
        public string $explanation,
        #[SerializedName('media_type')]
        public string $mediaType,
        public ?string $url = null,
        #[SerializedName('hdurl')]
        public ?string $hdUrl = null,
        public ?string $copyright = null,
    ) {
    }
}
