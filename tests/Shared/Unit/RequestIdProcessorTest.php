<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Logging\RequestIdProcessor;
use App\Shared\RequestId\RequestIdHolder;
use App\Tests\Support\RequestIds;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RequestIdProcessorTest extends TestCase
{
    public function testAddsTheCurrentRequestId(): void
    {
        // Given
        $holder = new RequestIdHolder();
        $holder->set(RequestIds::FROM_CLIENT);

        // When
        $record = new RequestIdProcessor($holder)(self::record());

        // Then
        self::assertSame([RequestIdProcessor::REQUEST_ID => RequestIds::FROM_CLIENT], $record->extra);
    }

    public function testAddsNothingOutsideARequest(): void
    {
        // Given
        $holder = new RequestIdHolder();
        $holder->set(RequestIds::FROM_CLIENT);
        $holder->reset();

        // When
        $record = new RequestIdProcessor($holder)(self::record());

        // Then
        self::assertSame([], $record->extra);
    }

    private static function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'message');
    }
}
