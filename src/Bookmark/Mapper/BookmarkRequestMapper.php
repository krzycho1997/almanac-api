<?php

declare(strict_types=1);

namespace App\Bookmark\Mapper;

use App\Bookmark\Dto\Request\BookmarkListQuery;
use App\Bookmark\Dto\Request\CreateBookmarkRequest;
use App\Bookmark\PageRequest;
use App\Briefing\Contract\DiscoveryId;

final readonly class BookmarkRequestMapper
{
    public function convertToDiscoveryId(CreateBookmarkRequest $request): DiscoveryId
    {
        if (\is_string($request->discoveryId)) {
            return DiscoveryId::fromString($request->discoveryId);
        }

        throw new \LogicException('The discovery id is read only after validation.');
    }

    public function convertToPageRequest(BookmarkListQuery $query): PageRequest
    {
        return new PageRequest(self::number($query->page, PageRequest::FIRST_PAGE), self::number($query->limit, PageRequest::DEFAULT_LIMIT));
    }

    private static function number(mixed $value, int $default): int
    {
        if (\is_string($value)) {
            return (int) $value;
        }

        return $default;
    }
}
