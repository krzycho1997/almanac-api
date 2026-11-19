<?php

declare(strict_types=1);

namespace App\Tests\Support\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class HttpErrorController
{
    #[Route('/_test/http-error/{status}', name: 'test_http_error', requirements: ['status' => Requirement::DIGITS], methods: [Request::METHOD_GET])]
    public function __invoke(int $status): Response
    {
        throw HttpException::fromStatusCode($status);
    }
}
