<?php

declare(strict_types=1);

namespace App\Briefing\Model;

use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class PageRef
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $thumbnail,
        #[SerializedName('wikidataId')]
        public ?string $catalogueId = null,
    ) {
    }
}
