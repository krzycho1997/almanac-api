<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ExampleApiResponse
{
    /**
     * @param list<ExampleItem> $items
     */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $title,
        #[SerializedName('media_type')]
        public string $mediaType,
        #[Assert\NotBlank(allowNull: true), Assert\Url(protocols: ['https'])]
        public ?string $url = null,
        #[Assert\Valid]
        public array $items = [],
    ) {
    }
}
