<?php

declare(strict_types=1);

namespace App\Tests\Support\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AuthenticatedUserProbeController
{
    public const string PATH = '/api/bookmarks/_probe';
    public const string HEADER = 'X-Authenticated-User';
    public const int STATUS = Response::HTTP_I_AM_A_TEAPOT;

    #[Route(self::PATH, name: 'test_authenticated_user_probe', methods: [Request::METHOD_GET])]
    public function __invoke(#[CurrentUser] UserInterface $user): Response
    {
        throw new HttpException(self::STATUS, headers: [self::HEADER => $user->getUserIdentifier()]);
    }
}
