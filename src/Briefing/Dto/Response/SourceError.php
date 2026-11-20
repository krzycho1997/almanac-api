<?php

declare(strict_types=1);

namespace App\Briefing\Dto\Response;

enum SourceError: string
{
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';
    case Rejected = 'rejected';
    case Misconfigured = 'misconfigured';
    case InvalidPayload = 'invalid_payload';
}
