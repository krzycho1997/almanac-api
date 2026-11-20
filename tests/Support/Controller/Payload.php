<?php

declare(strict_types=1);

namespace App\Tests\Support\Controller;

final readonly class Payload
{
    public function __construct(
        public string $name,
    ) {
    }
}
