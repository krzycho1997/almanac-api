<?php

declare(strict_types=1);

namespace App\Identity\Entity;

use App\Identity\ProbeTokenIssuer;
use Symfony\Component\HttpFoundation\Request;

class ProbeEntityUsesServiceAndHttp
{
    public function __construct(
        public ProbeTokenIssuer $issuer,
        public Request $request,
    ) {
    }
}
