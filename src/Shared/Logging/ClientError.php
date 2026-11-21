<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ClientError
{
    private function __construct()
    {
    }

    public static function matches(\Throwable $exception): bool
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode() < Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        return false;
    }
}
