<?php

declare(strict_types=1);

namespace App\Identity\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProbeHttpException extends NotFoundHttpException
{
}
