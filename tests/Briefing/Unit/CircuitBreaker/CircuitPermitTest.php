<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\CircuitBreaker;

use App\Briefing\CircuitBreaker\CircuitPermit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CircuitPermitTest extends TestCase
{
    #[DataProvider('permits')]
    public function testTellsWhichPermitsCallTheUpstream(CircuitPermit $permit, bool $expected): void
    {
        // When / Then
        self::assertSame($expected, $permit->allowsCall());
    }

    /**
     * @return iterable<string, array{CircuitPermit, bool}>
     */
    public static function permits(): iterable
    {
        yield 'call' => [CircuitPermit::Call, true];
        yield 'trial' => [CircuitPermit::Trial, true];
        yield 'refused' => [CircuitPermit::Refused, false];
    }
}
