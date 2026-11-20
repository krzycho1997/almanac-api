<?php

declare(strict_types=1);

namespace App\Tests\Support\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class PayloadController
{
    #[Route('/_test/payload', name: 'test_payload', methods: [Request::METHOD_POST])]
    public function __invoke(#[MapRequestPayload] Payload $payload): Response
    {
        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
