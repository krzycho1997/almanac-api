<?php

declare(strict_types=1);

namespace App\Briefing\Source\Apod;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ApodApiResponse
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $date,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $title,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $explanation,
        #[SerializedName('media_type'), Assert\NotBlank(normalizer: 'trim')]
        public string $mediaType,
        #[Assert\NotBlank(allowNull: true), Assert\Url(protocols: ['http', 'https'])]
        public ?string $url = null,
        #[SerializedName('hdurl'), Assert\NotBlank(allowNull: true), Assert\Url(protocols: ['http', 'https'])]
        public ?string $hdUrl = null,
        public ?string $copyright = null,
    ) {
    }
}
