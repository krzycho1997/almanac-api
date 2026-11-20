<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/health', name: 'shared_health', methods: [Request::METHOD_GET])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
