<?php

declare(strict_types=1);

namespace App\Shared\RequestId;

use Symfony\Component\HttpFoundation\Request;

final class ProbeListenerReadsRequest
{
    public function run(Request $request): void
    {
    }
}
