<?php

declare(strict_types=1);

namespace App\Tests\Support\ErrorHandler;

use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[WithLogLevel(LogLevel::ERROR)]
final class ClientErrorLoggedAsError extends HttpException
{
    public function __construct()
    {
        parent::__construct(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
