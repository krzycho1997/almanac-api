<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit;

use App\Briefing\FailureKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class FailureKindTest extends TestCase
{
    #[DataProvider('kinds')]
    public function testDescribesEveryKind(FailureKind $kind, string $value, string $logLevel, bool $outage): void
    {
        // When / Then
        self::assertSame($value, $kind->value);
        self::assertSame($logLevel, $kind->logLevel());
        self::assertSame($outage, $kind->countsAsOutage());
    }

    /**
     * @return iterable<string, array{FailureKind, string, string, bool}>
     */
    public static function kinds(): iterable
    {
        yield 'unavailable' => [FailureKind::Unavailable, 'unavailable', LogLevel::WARNING, true];
        yield 'rate limited' => [FailureKind::RateLimited, 'rate_limited', LogLevel::WARNING, true];
        yield 'rejected' => [FailureKind::Rejected, 'rejected', LogLevel::NOTICE, false];
        yield 'misconfigured' => [FailureKind::Misconfigured, 'misconfigured', LogLevel::ERROR, false];
        yield 'invalid payload' => [FailureKind::InvalidPayload, 'invalid_payload', LogLevel::ERROR, false];
    }
}
