<?php

declare(strict_types=1);

namespace App\Briefing\Source\OnThisDay;

use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class OnThisDayPage
{
    public function __construct(
        #[SerializedName('normalizedtitle')]
        public string $title,
        #[SerializedName('content_urls')]
        public ?OnThisDayContentUrls $contentUrls = null,
        public ?string $description = null,
        public ?OnThisDayThumbnail $thumbnail = null,
        #[SerializedName('wikibase_item')]
        public ?string $wikidataId = null,
    ) {
    }

    public function url(): string
    {
        return (string) $this->contentUrls?->desktop?->page;
    }

    public function thumbnailUrl(): string
    {
        return (string) $this->thumbnail?->source;
    }
}
