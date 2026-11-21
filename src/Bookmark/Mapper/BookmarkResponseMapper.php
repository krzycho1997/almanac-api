<?php

declare(strict_types=1);

namespace App\Bookmark\Mapper;

use App\Bookmark\BookmarkEntry;
use App\Bookmark\Dto\Response\BookmarkResponse;
use App\Bookmark\Dto\Response\DiscoveryDto;
use App\Bookmark\Dto\Response\DiscoveryKind;
use App\Briefing\Contract\DiscoveryType;
use App\Briefing\Contract\DiscoveryView;

final readonly class BookmarkResponseMapper
{
    private const string DATE_FORMAT = 'Y-m-d';

    public function map(BookmarkEntry $entry): BookmarkResponse
    {
        return new BookmarkResponse($entry->bookmark->id->toRfc4122(), $entry->bookmark->createdAt, self::discovery($entry->discovery));
    }

    private static function discovery(DiscoveryView $view): DiscoveryDto
    {
        return new DiscoveryDto($view->id->toRfc4122(), self::kind($view->type), $view->date?->format(self::DATE_FORMAT), $view->title, $view->summary, $view->url);
    }

    private static function kind(DiscoveryType $type): DiscoveryKind
    {
        return match ($type) {
            DiscoveryType::Apod => DiscoveryKind::Apod,
            DiscoveryType::Event => DiscoveryKind::Event,
            DiscoveryType::Person => DiscoveryKind::Person,
        };
    }
}
