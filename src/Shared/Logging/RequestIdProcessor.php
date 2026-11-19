<?php

declare(strict_types=1);

namespace App\Shared\Logging;

use App\Shared\RequestId\RequestIdHolder;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

#[AsMonologProcessor]
final readonly class RequestIdProcessor implements ProcessorInterface
{
    public const string REQUEST_ID = 'request_id';

    public function __construct(
        private RequestIdHolder $holder,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $requestId = $this->holder->get();

        if (null === $requestId) {
            return $record;
        }

        return $record->with(extra: [self::REQUEST_ID => $requestId] + $record->extra);
    }
}
