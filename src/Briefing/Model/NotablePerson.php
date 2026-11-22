<?php

declare(strict_types=1);

namespace App\Briefing\Model;

use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class NotablePerson
{
    public function __construct(
        public int $year,
        public string $name,
        public ?string $description,
        public ?string $url,
        public ?string $thumbnail,
        #[SerializedName('wikidataId')]
        public ?string $catalogueId = null,
    ) {
    }
}
