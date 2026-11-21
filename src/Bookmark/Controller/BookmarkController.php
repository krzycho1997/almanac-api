<?php

declare(strict_types=1);

namespace App\Bookmark\Controller;

use App\Bookmark\BookmarkId;
use App\Bookmark\BookmarkService;
use App\Bookmark\Dto\Request\BookmarkListQuery;
use App\Bookmark\Dto\Request\CreateBookmarkRequest;
use App\Bookmark\Dto\Response\BookmarkListResponse;
use App\Bookmark\Dto\Response\BookmarkResponse;
use App\Bookmark\Mapper\BookmarkResponseMapper;
use App\Identity\Contract\AuthenticatedUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\Serialize;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final readonly class BookmarkController
{
    private const string COLLECTION = '/api/bookmarks';
    private const string ITEM = self::COLLECTION.'/{id}';
    private const array STRICT_PAYLOAD = [
        AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
        DenormalizerInterface::COLLECT_EXTRA_ATTRIBUTES_ERRORS => true,
    ];

    public function __construct(
        private BookmarkService $bookmarks,
        private BookmarkResponseMapper $mapper,
    ) {
    }

    #[Route(self::COLLECTION, name: 'bookmark_list', methods: [Request::METHOD_GET])]
    #[Serialize]
    public function list(
        #[CurrentUser]
        AuthenticatedUser $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        BookmarkListQuery $query = new BookmarkListQuery(),
    ): BookmarkListResponse {
        return $this->mapper->mapPage($this->bookmarks->page($user->id, $query->pageRequest()));
    }

    #[Route(self::COLLECTION, name: 'bookmark_create', methods: [Request::METHOD_POST])]
    #[Serialize(code: Response::HTTP_CREATED)]
    public function create(
        #[CurrentUser]
        AuthenticatedUser $user,
        #[MapRequestPayload(acceptFormat: JsonEncoder::FORMAT, serializationContext: self::STRICT_PAYLOAD, mapWhenEmpty: true)]
        CreateBookmarkRequest $request,
    ): BookmarkResponse {
        return $this->mapper->map($this->bookmarks->add($user->id, $request->discoveryId()));
    }

    #[Route(self::ITEM, name: 'bookmark_delete', requirements: ['id' => Requirement::UUID_V7], methods: [Request::METHOD_DELETE])]
    public function delete(
        #[CurrentUser]
        AuthenticatedUser $user,
        BookmarkId $id,
    ): Response {
        $this->bookmarks->remove($user->id, $id);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
