<?php

declare(strict_types=1);

namespace App\Briefing\Model;

final readonly class NotablePerson
{
    public function __construct(
        public int $year,
        public string $name,
        public ?string $description,
        public ?string $url,
        public ?string $thumbnail,
        public ?string $wikidataId = null,
    ) {
    }
}
