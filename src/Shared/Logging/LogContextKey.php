<?php

declare(strict_types=1);

namespace App\Shared\Logging;

enum LogContextKey: string
{
    case Count = 'count';
    case Date = 'date';
    case Dates = 'dates';
    case DurationMs = 'durationMs';
    case ErrorType = 'errorType';
    case Exception = 'exception';
    case ExpiresAt = 'expiresAt';
    case Failures = 'failures';
    case Fields = 'fields';
    case From = 'from';
    case HttpStatus = 'httpStatus';
    case Kind = 'kind';
    case OpenSeconds = 'openSeconds';
    case Operation = 'operation';
    case Outcome = 'outcome';
    case Reason = 'reason';
    case RetryAt = 'retryAt';
    case RevokedCount = 'revokedCount';
    case Source = 'source';
    case To = 'to';
    case Trial = 'trial';
    case UserId = 'userId';
}
