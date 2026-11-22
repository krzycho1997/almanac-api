<?php

declare(strict_types=1);

namespace App\Tests\Briefing\Unit\Dto;

use App\Briefing\Dto\Input\PrefetchInput;
use App\Briefing\Dto\Input\Validator\ValidPrefetchRange;
use App\Tests\Support\ValidatorWithClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PrefetchInputTest extends TestCase
{
    public function testAcceptsARangeOfExactlyTheLongestLength(): void
    {
        // Given
        $input = new PrefetchInput();
        $input->from = '2024-01-01';
        $input->to = '2024-12-31';

        // When
        $violations = ValidatorWithClock::create(new MockClock('2026-11-21 10:00'))->validate($input);

        // Then
        self::assertCount(0, $violations);
        self::assertCount(ValidPrefetchRange::MAX_DAYS, $input->toRange(new \DateTimeZone('UTC')));
    }
}
