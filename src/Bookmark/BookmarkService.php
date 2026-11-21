<?php

declare(strict_types=1);

namespace App\Bookmark;

use App\Bookmark\Entity\Bookmark;
use App\Bookmark\Exception\AlreadyBookmarkedException;
use App\Bookmark\Exception\BookmarkNotFoundException;
use App\Bookmark\Exception\DiscoveryNotFoundException;
use App\Bookmark\Repository\BookmarkRepository;
use App\Briefing\Contract\DiscoveryCatalog;
use App\Briefing\Contract\DiscoveryId;
use App\Briefing\Contract\DiscoveryView;
use App\Identity\Contract\UserId;
use Psr\Clock\ClockInterface;

final readonly class BookmarkService
{
    public function __construct(
        private BookmarkRepository $bookmarks,
        private DiscoveryCatalog $discoveries,
        private ClockInterface $clock,
    ) {
    }

    public function add(UserId $owner, DiscoveryId $discoveryId): BookmarkEntry
    {
        $discovery = $this->discoveries->find($discoveryId) ?? throw DiscoveryNotFoundException::withId($discoveryId);
        $bookmark = new Bookmark($owner, $discoveryId, $this->clock->now());

        if ($this->bookmarks->insertIfAbsent($bookmark)) {
            return new BookmarkEntry($bookmark, $discovery);
        }

        throw AlreadyBookmarkedException::forDiscovery($discoveryId);
    }

    public function page(UserId $owner, PageRequest $request): BookmarkPage
    {
        $bookmarks = $this->bookmarks->findPageForUser($owner, $request);

        return new BookmarkPage($this->entriesFor($bookmarks), $this->bookmarks->countForUser($owner), $request);
    }

    public function remove(UserId $owner, BookmarkId $id): void
    {
        if ($this->bookmarks->deleteForUser($owner, $id)) {
            return;
        }

        throw BookmarkNotFoundException::withId($id);
    }

    /**
     * @param list<Bookmark> $bookmarks
     *
     * @return list<BookmarkEntry>
     */
    private function entriesFor(array $bookmarks): array
    {
        if ([] === $bookmarks) {
            return [];
        }

        return self::entries($bookmarks, $this->discoveries->findMany(...self::discoveryIdsOf($bookmarks)));
    }

    /**
     * @param list<Bookmark> $bookmarks
     *
     * @return list<DiscoveryId>
     */
    private static function discoveryIdsOf(array $bookmarks): array
    {
        return array_map(static fn (Bookmark $bookmark): DiscoveryId => $bookmark->discoveryId, $bookmarks);
    }

    /**
     * @param list<Bookmark>                         $bookmarks
     * @param array<non-empty-string, DiscoveryView> $discoveries
     *
     * @return list<BookmarkEntry>
     */
    private static function entries(array $bookmarks, array $discoveries): array
    {
        $entries = [];

        foreach ($bookmarks as $bookmark) {
            $discovery = $discoveries[$bookmark->discoveryId->toRfc4122()] ?? null;

            if ($discovery instanceof DiscoveryView) {
                $entries[] = new BookmarkEntry($bookmark, $discovery);
            }
        }

        return $entries;
    }
}
