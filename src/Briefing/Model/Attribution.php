<?php

declare(strict_types=1);

namespace App\Briefing\Model;

final readonly class Attribution
{
    public function __construct(
        public string $name,
        public string $url,
        public ?string $license,
        public ?string $licenseUrl,
        public bool $modified,
        public ?string $notice,
    ) {
    }
}
