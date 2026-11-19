<?php

declare(strict_types=1);

namespace App\Tests\Support\ErrorHandler;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ClientErrorImplementingTheErrorLevel extends HttpException implements LoggedAsError
{
    public function __construct()
    {
        parent::__construct(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
