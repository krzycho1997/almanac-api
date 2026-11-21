<?php

declare(strict_types=1);

namespace App\Bookmark\Controller;

use App\Bookmark\BookmarkService;
use App\Bookmark\Dto\Request\CreateBookmarkRequest;
use App\Bookmark\Dto\Response\BookmarkResponse;
use App\Bookmark\Mapper\BookmarkResponseMapper;
use App\Identity\Contract\AuthenticatedUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\Serialize;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final readonly class BookmarkController
{
    private const string COLLECTION = '/api/bookmarks';

    public function __construct(
        private BookmarkService $bookmarks,
        private BookmarkResponseMapper $mapper,
    ) {
    }

    #[Route(self::COLLECTION, name: 'bookmark_create', methods: [Request::METHOD_POST])]
    #[Serialize(code: Response::HTTP_CREATED)]
    public function create(
        #[CurrentUser]
        AuthenticatedUser $user,
        #[MapRequestPayload(acceptFormat: JsonEncoder::FORMAT, mapWhenEmpty: true)]
        CreateBookmarkRequest $request,
    ): BookmarkResponse {
        return $this->mapper->map($this->bookmarks->add($user->id, $request->discoveryId()));
    }
}
