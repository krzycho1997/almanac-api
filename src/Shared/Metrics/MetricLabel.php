<?php

declare(strict_types=1);

namespace App\Shared\Metrics;

enum MetricLabel: string
{
    case Route = 'route';
    case Method = 'method';
    case Code = 'code';
    case Source = 'source';
    case Decision = 'decision';
    case Day = 'day';
    case Result = 'result';
    case Status = 'status';
    case To = 'to';
}
