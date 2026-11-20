<?php

declare(strict_types=1);

namespace App\Tests\Support\Briefing;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ExampleItem
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $text,
        public int $year,
    ) {
    }
}
