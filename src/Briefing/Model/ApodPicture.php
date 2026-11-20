<?php

declare(strict_types=1);

namespace App\Briefing\Model;

final readonly class ApodPicture implements SourcePayload
{
    public function __construct(
        public \DateTimeImmutable $date,
        public string $title,
        public string $explanation,
        public MediaType $mediaType,
        public ?string $url,
        public ?string $hdUrl,
        public ?string $credit,
        public Attribution $attribution,
    ) {
    }
}
