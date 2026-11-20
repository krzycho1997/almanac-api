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
    public function testDescribesEveryKind(FailureKind $kind, string $value, string $logLevel): void
    {
        // When / Then
        self::assertSame($value, $kind->value);
        self::assertSame($logLevel, $kind->logLevel());
    }

    /**
     * @return iterable<string, array{FailureKind, string, string}>
     */
    public static function kinds(): iterable
    {
        yield 'unavailable' => [FailureKind::Unavailable, 'unavailable', LogLevel::WARNING];
        yield 'rate limited' => [FailureKind::RateLimited, 'rate_limited', LogLevel::WARNING];
        yield 'rejected' => [FailureKind::Rejected, 'rejected', LogLevel::NOTICE];
        yield 'misconfigured' => [FailureKind::Misconfigured, 'misconfigured', LogLevel::ERROR];
        yield 'invalid payload' => [FailureKind::InvalidPayload, 'invalid_payload', LogLevel::ERROR];
    }
}
