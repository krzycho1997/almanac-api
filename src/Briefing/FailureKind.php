<?php

declare(strict_types=1);

namespace App\Briefing;

use Psr\Log\LogLevel;

enum FailureKind: string
{
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';
    case Rejected = 'rejected';
    case Misconfigured = 'misconfigured';
    case InvalidPayload = 'invalid_payload';

    /**
     * @return LogLevel::*
     */
    public function logLevel(): string
    {
        return match ($this) {
            self::Unavailable, self::RateLimited => LogLevel::WARNING,
            self::Rejected => LogLevel::NOTICE,
            self::Misconfigured, self::InvalidPayload => LogLevel::ERROR,
        };
    }
}
