<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class PersonDto
{
    public function __construct(
        public int $year,
        public string $name,
        public ?string $description,
        public ?string $url,
        public ?string $thumbnail,
    ) {
    }
}
