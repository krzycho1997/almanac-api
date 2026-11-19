<?php

declare(strict_types=1);

namespace App\Tests\Support\ErrorHandler;

use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Attribute\WithLogLevel;

#[WithLogLevel(LogLevel::ERROR)]
interface LoggedAsError
{
}
