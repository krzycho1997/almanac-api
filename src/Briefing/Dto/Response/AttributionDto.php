<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

final readonly class AttributionDto
{
    public function __construct(
        public SourceKey $source,
        public string $name,
        public string $url,
        public ?string $license,
        public ?string $licenseUrl,
        public bool $modified,
        public ?string $notice,
    ) {
    }
}
